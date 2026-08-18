<?php
/**
 * webrtc_turn_settings.php - Pengaturan server TURN/STUN (WebRTC) GLOBAL,
 * berlaku untuk SEMUA akun/owner tanpa kecuali (dipakai bareng oleh CS Call
 * Center, dan bisa dipakai fitur WebRTC lain ke depan). Menu Administrator
 * Panel, KHUSUS ADMIN -- bukan checkbox hide-tombol yang bisa diberikan ke
 * ASSISTANT/owner manapun, sama pola dengan otp_signup_settings.php.
 *
 * Config ini disimpan di baris owner_key='admin' tabel
 * `cs_call_center_settings` (kolom webrtc_* saja) lewat
 * csCallCenterSaveGlobalWebrtcConfig()/csCallCenterGetGlobalWebrtcConfig()
 * di cs_call_center_helper.php -- SATU-SATUNYA sumber TURN/STUN yang dipakai
 * runtime oleh SEMUA owner (lihat csCallCenterResolveWebrtcConfig()).
 */
require 'header.php';

if (!isset($AKSES) || $AKSES !== 'ADMIN') {
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>Halaman ini hanya untuk ADMIN.</div></div>";
    require 'footer.php';
    exit;
}

require_once __DIR__ . '/cs_call_center_helper.php';
csCallCenterEnsureTables($conn);

$saved = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stunUrl = trim((string) ($_POST['webrtc_stun_url'] ?? ''));
    $turnUrl = trim((string) ($_POST['webrtc_turn_url'] ?? ''));
    if ($stunUrl === '' || $turnUrl === '') {
        $error = 'STUN URL dan TURN URL wajib diisi.';
    } else {
        $ok = csCallCenterSaveGlobalWebrtcConfig($conn, [
            'webrtc_stun_url' => $stunUrl,
            'webrtc_turn_url' => $turnUrl,
            'webrtc_turn_username' => trim((string) ($_POST['webrtc_turn_username'] ?? '')),
            'webrtc_turn_credential' => trim((string) ($_POST['webrtc_turn_credential'] ?? '')),
            'debug_mode' => isset($_POST['debug_mode']),
        ]);
        if ($ok) {
            $saved = true;
        } else {
            $error = 'Gagal menyimpan ke database: ' . mysqli_error($conn);
        }
    }
}

$current = csCallCenterGetGlobalWebrtcConfig($conn);
$default = csCallCenterDefaultWebrtc();
$isUsingDefault = ($current['webrtc_stun_url'] === $default['webrtc_stun_url'] && $current['webrtc_turn_url'] === $default['webrtc_turn_url']);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12 col-lg-8 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <span class="fw-bold"><i class="fas fa-phone-volume me-2"></i>Server TURN/STUN (Call)</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Config ini berlaku <strong>GLOBAL untuk SEMUA akun/owner</strong> di billing ini, dipakai fitur
                        <strong>CS Call Center</strong> (dan fitur panggilan WebRTC lain ke depan). Sama persis dengan
                        server yang sudah dipakai <strong>QChat</strong> &amp; <strong>MyNVR Walkie-Talkie</strong>
                        (dua fitur yang sudah terbukti bisa lintas jaringan) -- kosongkan/reset ke nilai bawaan kalau ragu.
                    </div>

                    <?php if ($saved): ?>
                        <div class="alert alert-success py-2"><i class="fas fa-check-circle me-1"></i>Pengaturan berhasil disimpan.</div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <div class="alert <?php echo $isUsingDefault ? 'alert-secondary' : 'alert-warning'; ?> py-2 small">
                        <?php echo $isUsingDefault
                            ? '<i class="fas fa-circle-check me-1"></i>Saat ini memakai <strong>server bawaan</strong> (belum pernah diubah manual).'
                            : '<i class="fas fa-triangle-exclamation me-1"></i>Saat ini memakai <strong>server KUSTOM</strong> (sudah pernah diubah dari nilai bawaan).'; ?>
                    </div>

                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">STUN Server URL <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="webrtc_stun_url" required
                                value="<?php echo htmlspecialchars($current['webrtc_stun_url']); ?>" placeholder="stun:host:port">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">TURN Server URL <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="webrtc_turn_url" required
                                value="<?php echo htmlspecialchars($current['webrtc_turn_url']); ?>" placeholder="turn:host:port">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">TURN Username</label>
                            <input type="text" class="form-control" name="webrtc_turn_username"
                                value="<?php echo htmlspecialchars($current['webrtc_turn_username']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">TURN Credential</label>
                            <input type="text" class="form-control" name="webrtc_turn_credential"
                                value="<?php echo htmlspecialchars($current['webrtc_turn_credential']); ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="debug_mode" id="webrtcDebugToggle"
                                    <?php echo !empty($current['debug_mode']) ? 'checked' : ''; ?> style="width:3em;height:1.5em;">
                                <label class="form-check-label" for="webrtcDebugToggle">
                                    Mode Debug (tampilkan log teknis panggilan di layar)
                                </label>
                                <div class="form-text">Kalau AKTIF, kotak log hitam kecil akan muncul di halaman panggilan
                                    (sisi agent &amp; pelanggan) berisi status ICE/koneksi -- berguna kalau ada laporan
                                    "panggilan gagal/tidak ada suara" dan perlu didiagnosis. Matikan lagi kalau sudah
                                    tidak perlu, supaya tampilan panggilan tetap bersih untuk pemakaian sehari-hari.</div>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan</button>
                            <button type="submit" name="reset_default" value="1" class="btn btn-outline-secondary"
                                onclick="document.querySelector('[name=webrtc_stun_url]').value=<?php echo json_encode($default['webrtc_stun_url']); ?>;document.querySelector('[name=webrtc_turn_url]').value=<?php echo json_encode($default['webrtc_turn_url']); ?>;document.querySelector('[name=webrtc_turn_username]').value=<?php echo json_encode($default['webrtc_turn_username']); ?>;document.querySelector('[name=webrtc_turn_credential]').value=<?php echo json_encode($default['webrtc_turn_credential']); ?>;">
                                <i class="fas fa-rotate-left me-1"></i>Isi Nilai Bawaan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-book me-2"></i>
                    <span class="fw-bold">Panduan Instal Server STUN/TURN Sendiri (coturn)</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Kalau kolom STUN/TURN di atas dikosongkan, sistem otomatis pakai server WebRTC bawaan. Panduan ini
                        HANYA perlu diikuti kalau Anda mau pakai server STUN/TURN sendiri (VPS/server pribadi) -- misalnya
                        krn kebutuhan kapasitas/privasi data suara sendiri.
                    </div>

                    <h6 class="fw-bold">1. Instal coturn (VPS Ubuntu/Debian)</h6>
                    <p class="small text-muted mb-1">coturn adalah software STUN/TURN server open-source paling umum dipakai. Jalankan di VPS dgn IP publik:</p>
                    <pre class="bg-dark text-light p-2 rounded small" style="white-space:pre-wrap;">sudo apt update
sudo apt install coturn -y
sudo nano /etc/turnserver.conf</pre>
                    <p class="small text-muted mb-1">Isi/pastikan baris berikut ada di <code>/etc/turnserver.conf</code> (sesuaikan IP publik, realm, dan user/password sendiri):</p>
                    <pre class="bg-dark text-light p-2 rounded small" style="white-space:pre-wrap;">listening-port=3478
tls-listening-port=5349
min-port=49152
max-port=65535
external-ip=&lt;IP_PUBLIK_VPS&gt;
realm=domainanda.com
user=namauser:passwordanda
lt-cred-mech
fingerprint</pre>
                    <p class="small text-muted mb-1">Aktifkan lalu jalankan servicenya:</p>
                    <pre class="bg-dark text-light p-2 rounded small" style="white-space:pre-wrap;">sudo sed -i 's/#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn
sudo systemctl enable coturn
sudo systemctl restart coturn</pre>
                    <p class="small text-muted">Setelah jalan, isi kolom di atas: <strong>STUN Server URL</strong> = <code>stun:IP_PUBLIK_VPS:3478</code>, <strong>TURN Server URL</strong> = <code>turn:IP_PUBLIK_VPS:3478</code>, <strong>TURN Username/Credential</strong> = sesuai baris <code>user=</code> di atas.</p>

                    <hr>

                    <h6 class="fw-bold">2. Port yang Wajib Dibuka (firewall VPS/cloud provider)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered small mb-3">
                            <thead class="table-light">
                                <tr><th>Port</th><th>Protokol</th><th>Kegunaan</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>3478</td><td>UDP &amp; TCP</td><td>Signaling STUN/TURN utama (wajib)</td></tr>
                                <tr><td>5349</td><td>TCP</td><td>TURN via TLS/TURNS (opsional, lebih tahan diblokir firewall ketat)</td></tr>
                                <tr><td>49152 - 65535</td><td>UDP</td><td>Relay data suara (RTP/RTCP) saat TURN dipakai -- WAJIB dibuka rentang penuh</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted">Kalau pakai firewall OS (ufw/firewalld), contoh utk ufw:</p>
                    <pre class="bg-dark text-light p-2 rounded small" style="white-space:pre-wrap;">sudo ufw allow 3478/tcp
sudo ufw allow 3478/udp
sudo ufw allow 5349/tcp
sudo ufw allow 49152:65535/udp</pre>

                    <hr>

                    <h6 class="fw-bold">3. Kalau Server STUN/TURN Ada di Belakang MikroTik (NAT)</h6>
                    <p class="small text-muted mb-1">
                        Kalau VPS/server coturn-nya sebenarnya ada di jaringan LOKAL di belakang router MikroTik (bukan IP publik
                        langsung), IP publik yang dipakai justru IP WAN MikroTik-nya, dan port-port di atas HARUS di-forward
                        (dst-nat) ke IP lokal server coturn. Contoh rule persis spt gambar (4 rule: data relay, STUN/TURN UDP,
                        STUN/TURN TCP, TLS) -- ganti <code>&lt;IP_LOKAL_COTURN&gt;</code> dgn IP LAN server coturn Anda:
                    </p>
                    <pre class="bg-dark text-light p-2 rounded small" style="white-space:pre-wrap;">/ip firewall nat add chain=dstnat protocol=udp dst-port=3478 action=dst-nat to-addresses=&lt;IP_LOKAL_COTURN&gt; to-ports=3478 comment="WebRTC_STUN_TURN_UDP"
/ip firewall nat add chain=dstnat protocol=tcp dst-port=3478 action=dst-nat to-addresses=&lt;IP_LOKAL_COTURN&gt; to-ports=3478 comment="WebRTC_STUN_TURN_TCP"
/ip firewall nat add chain=dstnat protocol=udp dst-port=49152-65535 action=dst-nat to-addresses=&lt;IP_LOKAL_COTURN&gt; to-ports=49152-65535 comment="WebRTC_Relay_Data"
/ip firewall nat add chain=dstnat protocol=tcp dst-port=5349 action=dst-nat to-addresses=&lt;IP_LOKAL_COTURN&gt; to-ports=5349 comment="WebRTC_TLS"</pre>
                    <p class="small text-muted mb-0">
                        Setelah rule dst-nat dibuat, pastikan juga tidak ada rule <code>firewall filter</code> lain yang MEMBLOKIR
                        port-port tsb dari luar (chain <code>input</code>/<code>forward</code>). Lalu isi kolom STUN/TURN URL di
                        atas pakai <strong>IP PUBLIK/WAN MikroTik</strong> (bukan IP lokal coturn-nya), krn itu yang dihubungi
                        dari luar oleh browser pelanggan/agent.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
