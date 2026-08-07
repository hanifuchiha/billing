<?php

require 'cek-sesi.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Panduan', $akses_menu, true)) {
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;"><h3>Anda tidak memiliki akses ke menu Panduan.</h3></body></html>';
        exit;
    }
}

$ui_visibility_defaults = [
    'cards_semua_halaman' => true,
    'buttons_semua_halaman' => true,
    'buttons_dashboard' => true,
    'buttons_customer' => true,
    'buttons_server' => true,
    'buttons_vpn' => true,
    'buttons_tiket' => true,
    'buttons_odp' => true,
    'buttons_olt' => true,
    'buttons_notification' => true,
];
$ui_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$safe_ui_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ui_settings_username);
if ($safe_ui_username !== '') {
    $ui_settings_file = __DIR__ . '/settings/dashboard-cards-' . $safe_ui_username . '.json';
    if (is_file($ui_settings_file)) {
        $ui_raw = @file_get_contents($ui_settings_file);
        $ui_decoded = json_decode((string)$ui_raw, true);
        if (is_array($ui_decoded)) {
            foreach ($ui_visibility_defaults as $ui_key => $ui_default) {
                if (array_key_exists($ui_key, $ui_decoded)) {
                    $ui_visibility_defaults[$ui_key] = (bool)$ui_decoded[$ui_key];
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Panduan Koneksi ke CRM Billing</title>
    <!--     Fonts and icons     -->
    <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700,800" rel="stylesheet" />
    <!-- Nucleo Icons -->
    <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-svg.css" rel="stylesheet" />
    <!-- Font Awesome Icons -->
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <!-- CSS Files -->
    <link id="pagestyle" href="../assets/css/soft-ui-dashboard.css?v=1.1.0" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Nepcha Analytics (nepcha.com) -->
    <!-- Tambahkan Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Nepcha is a easy-to-use web analytics. No cookies and fully compliant with GDPR, CCPA and PECR. -->
    <script defer data-site="YOUR_DOMAIN_HERE" src="https://api.nepcha.com/js/nepcha-analytics.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            window.billingUiVisibility = <?php echo json_encode($ui_visibility_defaults, JSON_UNESCAPED_UNICODE); ?>;
            
            // Determine current page name for per-page button visibility
            function getCurrentPageName() {
                const pathname = window.location.pathname || '';
                const filename = pathname.split('/').pop() || 'unknown';
                return filename.replace('.php', '').toLowerCase();
            }

            // Map page filenames to setting keys
            function getPageButtonSettingKey(pageName) {
                const pageMap = {
                    'dashboard': 'buttons_dashboard',
                    'daftar_pelanggan': 'buttons_customer',
                    'customer': 'buttons_customer',
                    'server': 'buttons_server',
                    'vpn': 'buttons_vpn',
                    'vpnadmin': 'buttons_vpn',
                    'tiket_manager': 'buttons_tiket',
                    'odp': 'buttons_odp',
                    'olt': 'buttons_olt',
                    'mynetworkmap': 'buttons_notification',
                    'notification': 'buttons_notification',
                };
                return pageMap[pageName] || null;
            }

            document.addEventListener('DOMContentLoaded', function() {
                if (window.__billingUiVisibilityApplied) return;
                window.__billingUiVisibilityApplied = true;

                const settings = (window.billingUiVisibility && typeof window.billingUiVisibility === 'object')
                    ? window.billingUiVisibility
                    : {};

                const mainContent = document.querySelector('main.main-content') || document.body;
                if (!mainContent) return;

                if (settings.cards_semua_halaman === false) {
                    mainContent.querySelectorAll('.card, .card-box').forEach(function(el) {
                        if (el.closest('.modal')) return;
                        el.style.display = 'none';
                    });
                }

                // Apply global button visibility
                if (settings.buttons_semua_halaman === false) {
                    mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
                        if (el.closest('.modal')) return;
                        el.style.display = 'none';
                    });
                } else {
                    // If global buttons are enabled, check per-page button visibility
                    const currentPage = getCurrentPageName();
                    const pageButtonKey = getPageButtonSettingKey(currentPage);
                    
                    if (pageButtonKey && settings[pageButtonKey] === false) {
                        mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
                            if (el.closest('.modal')) return;
                            el.style.display = 'none';
                        });
                    }
                }
            });
        </script>
    <!-- Tampilkan Peta -->
    <style>
        #map {
            height: 20px;
            width: 10%;
        }
    </style>
</head>

<style>
    body {
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
    }

    #loading {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: white;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #ccc;
        border-top-color: #007bff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    .step {
        margin-bottom: 2rem;
        padding: 1.5rem;
        border-left: 4px solid #007bff;
        background: #f8f9fa;
        border-radius: 0 5px 5px 0;
    }

    .step h4 {
        color: #007bff;
        margin-bottom: 0.5rem;
    }

    .code-block {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 5px;
        padding: 1rem;
        margin: 1rem 0;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
    }

    .accordion-button:not(.collapsed) {
        background-color: #e7f3ff;
        color: #007bff;
    }
</style>
<div id="loading">
    <div class="spinner"></div>
</div>







<body id="content" class="g-sidenav-show  bg-gray-100">
 

    <div class="py-4">
        <div class="row">
            <div class="col-12">
                <div class="mb-4">
                    <div class="pb-0">

                        <!-- Ringkasan Panduan Pengguna Non-Teknis -->
                        <div class="alert alert-primary mb-4">
                            <h4 class="mb-2"><i class="fas fa-info-circle"></i> Ringkasan Panduan Pengguna</h4>
                            <ul class="mb-0">
                                <li><b>1. Lengkapi Data Profil</b>: Pastikan data bisnis dan kontak Anda sudah benar agar transaksi dan notifikasi berjalan lancar.</li>
                                <li><b>2. Tambahkan Server & Paket</b>: Daftarkan perangkat dan paket layanan yang akan ditawarkan kepada pelanggan.</li>
                                <li><b>3. Daftarkan Pelanggan</b>: Input data pelanggan baru dan pilihkan paket sesuai kebutuhan mereka.</li>
                                <li><b>4. Pantau Pembayaran</b>: Sistem akan membuat tagihan otomatis dan mengirim pengingat pembayaran ke pelanggan.</li>
                                <li><b>5. Layanan Otomatis</b>: Jika pelanggan menunggak, layanan akan dinonaktifkan otomatis dan aktif kembali setelah pembayaran diterima.</li>
                                <li><b>6. Notifikasi & Laporan</b>: Semua notifikasi dikirim otomatis, dan Anda dapat memantau laporan keuangan serta status pelanggan melalui dashboard.</li>
                                <li><b>7. Bantuan</b>: Jika mengalami kendala, gunakan menu bantuan atau hubungi admin/support.</li>
                            </ul>
                        </div>
                        <h1>Panduan Lengkap CRM Billing System</h1>
                        <p class="lead">Dokumentasi komprehensif untuk setup dan penggunaan sistem CRM Billing</p>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle"></i> Tentang Sistem Ini</h5>
                            <p>CRM Billing System adalah platform lengkap untuk mengelola bisnis provider internet, termasuk monitoring jaringan Mikrotik, manajemen pelanggan PPPoE/Hotspot, billing otomatis, dan integrasi payment gateway.</p>
                        </div>

                        <h3>Daftar Isi</h3>
                        <ul>
                            <li><a href="#persiapan">1. Persiapan Awal</a></li>
                            <li><a href="#server">2. Konfigurasi Server Mikrotik</a></li>
                            <li><a href="#jaringan">3. Manajemen Jaringan (OLT, ODP, Pool IP, VLAN, NMS, Peta FTTH)</a></li>
                            <li><a href="#paket">4. Manajemen Paket Layanan & Voucher</a></li>
                            <li><a href="#pelanggan">5. Manajemen Pelanggan</a></li>
                            <li><a href="#pembayaran">6. Sistem Pembayaran & Billing</a></li>
                            <li><a href="#komunikasi">7. Komunikasi & Notifikasi (WA, Telegram, Email)</a></li>
                            <li><a href="#monitoring">8. Monitoring & Laporan</a></li>
                            <li><a href="#tim">9. Tim, Sales/Mitra & Hak Akses ASSISTANT</a></li>
                            <li><a href="#sistem">10. Pengaturan Sistem & Portal Pelanggan</a></li>
                            <li><a href="#tiket">11. Tiket & Provisioning</a></li>
                            <li><a href="#troubleshoot">12. Troubleshooting & FAQ</a></li>
                        </ul>

                        <h3 id="persiapan">1. Persiapan Awal</h3>
                        <div class="step">
                            <h4>1.1 Lengkapi Data Profil</h4>
                            <p>Data profil lengkap diperlukan untuk dokumentasi transaksi dan identitas bisnis.</p>
                            <a class="btn btn-primary" href="user.php" target="_blank"><i class="fas fa-user-edit"></i> Lengkapi Profil</a>
                        </div>


                        <div class="step">
                            <h4>1.2 Setup VPN (Akses Jaringan Aman)</h4>
                            <ol>
                                <li>
                                    <b>Beli & Aktifkan VPN</b><br>
                                    <ul>
<?php if ($AKSES != "ADMIN") { ?>
                                        <li>Buka menu <a class="btn btn-success btn-sm" href="vpn.php" target="_blank"><i class="fas fa-shield-alt"></i> Beli VPN</a></li>
                     <?php }?>  
                     <?php if ($AKSES == "ADMIN") { ?>
                                        <li>Buka menu <a class="btn btn-success btn-sm" href="vpnadmin.php" target="_blank"><i class="fas fa-shield-alt"></i> Buat VPN</a></li>
                     <?php }?>                      
                                        
                                        <li>Isi username, pilih port, dan paket bulanan sesuai kebutuhan.</li>
                                        <li>Setelah pembayaran, data VPN (IP, user, pass, port) akan muncul di dashboard Anda.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Konfigurasi VPN di Mikrotik</b><br>
                                    <ul>
                                        <li>Gunakan script yang tersedia di dashboard VPN untuk menambah koneksi ke Mikrotik Anda (copy-paste via terminal Winbox/WebFig).</li>
                                        <li>Pastikan memilih service (L2TP/PPTP) sesuai yang didukung perangkat Anda.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Forwarding Port ke Lokal (Opsional)</b><br>
                                    <ul>
                                        <li>Jika ingin remote perangkat lokal (OLT, NVR, dll) dari luar, gunakan script NAT forwarding yang disediakan.</li>
                                        <li>Ganti [GANTI IP PERANGKAT ANDA] dan [PORT TUJUAN PERANGKAT ANDA] sesuai perangkat tujuan.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Panduan Lengkap</b><br>
                                    <a class="btn btn-info btn-sm" href="panduan config VPN ke mikrtoik anda.pdf" target="_blank"><i class="fas fa-file-pdf"></i> Download Panduan PDF</a>
                                </li>
                            </ol>
                            <div class="alert alert-info mt-2">
                                <b>Tips:</b> Pastikan VPN aktif sebelum menghubungkan Mikrotik ke server billing. Jika gagal konek, cek username/password dan port, serta pastikan firewall tidak memblokir koneksi.
                            </div>
                        </div>

                        <div class="step">
                            <h4>1.3 Test Koneksi</h4>
                            <p>Pastikan Mikrotik dapat mengakses server billing.</p>
                            <div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 5px; padding: 1rem; margin: 1rem 0; font-family: 'Courier New', monospace;">
/ping <?php echo $config['webiplocal'] ?? 'IP_SERVER_BILLING'; ?> count=5
                            </div>
                        </div>


                        <h3 id="server">2. Konfigurasi Server Mikrotik</h3>
                        <div class="step">
                            <h4>2.1 Tambah & Setting Server Mikrotik</h4>
                            <ol>
                                <li>
                                    <b>Tambah Server Baru</b><br>
                                    <ul>
                                        <li>Buka menu <a class="btn btn-primary btn-sm" href="server.php" target="_blank"><i class="fas fa-server"></i> Konfigurasi Server</a></li>
                                        <li>Klik <b>Add New Server</b>, lalu isi data: Brand, Area, IP/Domain (IP VPN Mikrotik), API Port (default: 8728), Web Port (default: 80), Username, dan Password Mikrotik.</li>
                                        <li>Simpan. Server akan muncul di daftar dan siap dipantau.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Aktifkan Interface PPPoE/Hotspot</b><br>
                                    <ul>
                                        <li>Pilih server dari daftar, lalu ceklist interface yang ingin diaktifkan untuk PPPoE dan/atau Hotspot.</li>
                                        <li>Klik <b>Update Server</b> untuk menyimpan pengaturan interface.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Tips & Troubleshooting</b><br>
                                    <ul>
                                        <li>Pastikan IP/Domain dan port API benar serta dapat diakses dari server billing (cek firewall/VPN).</li>
                                        <li>Gunakan username/password Mikrotik dengan hak akses penuh (admin).</li>
                                        <li>Jika gagal connect, cek status VPN dan pastikan API service di Mikrotik sudah <code>enabled</code> (<code>/ip service set api disabled=no</code>).</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>

                        <h3 id="jaringan">3. Manajemen Jaringan</h3>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-hdd fa-3x text-success mb-3"></i>
                                    <h5>OLT Management</h5>
                                    <p>Kelola OLT GPON/EPON</p>
                                    <a href="olt.php" class="btn btn-sm btn-outline-primary" target="_blank">Kelola OLT</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-map-marker-alt fa-3x text-info mb-3"></i>
                                    <h5>ODP Management</h5>
                                    <p>Optical Distribution Point</p>
                                    <a href="odp.php" class="btn btn-sm btn-outline-primary" target="_blank">Kelola ODP</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-globe fa-3x text-warning mb-3"></i>
                                    <h5>IP Pool</h5>
                                    <p>Manajemen Pool IP</p>
                                    <a href="pool.php" class="btn btn-sm btn-outline-primary" target="_blank">Kelola Pool</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-ethernet fa-3x text-secondary mb-3"></i>
                                    <h5>VLAN</h5>
                                    <p>Manajemen VLAN antar server</p>
                                    <a href="vlan.php" class="btn btn-sm btn-outline-primary" target="_blank">Kelola VLAN</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-project-diagram fa-3x text-danger mb-3"></i>
                                    <h5>NMS (Network Monitoring)</h5>
                                    <p>Pantau status perangkat jaringan</p>
                                    <a href="mynetworkmap.php" class="btn btn-sm btn-outline-primary" target="_blank">Buka NMS</a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border p-3 mb-3 text-center">
                                    <i class="fas fa-project-diagram fa-3x text-primary mb-3"></i>
                                    <h5>Peta Infrastruktur (FTTH Maps)</h5>
                                    <p>Peta jalur kabel & perangkat FTTH</p>
                                    <a href="ftth_maps.php" class="btn btn-sm btn-outline-primary" target="_blank">Buka Peta</a>
                                </div>
                            </div>
                        </div>

                        <div class="step">
                            <h4>3.1 VLAN -- Pisahkan Jaringan per Server/Area</h4>
                            <p>VLAN dipakai kalau Anda punya beberapa server/area yang perlu dipisahkan jaringannya secara logis (misal 1 Mikrotik melayani 2 area berbeda, atau Anda ingin memisahkan trafik pelanggan per cluster).</p>
                            <p><b>Simulasi:</b> Anda punya area "Cilangkap" dan "Klender" yang dilayani 1 Mikrotik. Di menu VLAN, tambahkan VLAN ID 10 untuk Cilangkap dan VLAN ID 20 untuk Klender, lalu hubungkan tiap VLAN ke Server Area yang sesuai. Sekarang pelanggan di kedua area tetap terpisah jaringannya walau 1 perangkat fisik.</p>
                        </div>

                        <div class="step">
                            <h4>3.2 NMS -- Pantau Kondisi Perangkat Real-time</h4>
                            <p>NMS (Network Monitoring System) menampilkan status online/offline perangkat jaringan Anda (Mikrotik, OLT, dll) dalam satu peta/dashboard, jadi Anda tahu ada gangguan tanpa harus cek satu-satu.</p>
                            <p><b>Simulasi:</b> Listrik padam di satu ODP dan OLT di sana ikut mati. Di halaman NMS, ikon perangkat itu otomatis berubah jadi merah/offline -- Anda langsung tahu titik gangguannya tanpa menunggu laporan pelanggan.</p>
                        </div>

                        <div class="step">
                            <h4>3.3 Peta Infrastruktur (FTTH Maps)</h4>
                            <p>Menggambar & menyimpan peta jalur kabel fiber, lokasi ODP/OLT/Joint Closure (JC) di atas peta asli (mirip Google Maps), supaya tim lapangan tahu persis rute kabel dan titik-titik penting di jaringan Anda.</p>
                            <p><b>Simulasi:</b> Tim instalasi baru saja menarik kabel dari OLT ke ODP baru di ujung gang. Di halaman ini, gambarkan jalur kabelnya (fitur draw cable), tandai lokasi ODP barunya, lalu simpan. Nanti kalau ada laporan putus kabel, tim teknisi tinggal buka peta ini untuk tahu rute persis yang harus dicek.</p>
                        </div>

                        <div class="step">
                            <h4>3.4 Isolir Forwarding (khusus Admin)</h4>
                            <p>Mengatur halaman/pesan yang muncul ketika pelanggan yang sedang diisolir (internetnya dimatikan karena menunggak) mencoba membuka browser -- biasanya berupa halaman "Internet Anda diisolir, silakan bayar tagihan" lengkap dengan link pembayaran, bukan sekadar error koneksi biasa.</p>
                            <p><b>Simulasi:</b> Pelanggan yang menunggak buka browser untuk browsing seperti biasa. Alih-alih error "tidak bisa connect", dia otomatis diarahkan (redirect) ke halaman isolir yang menampilkan info tagihan dan tombol bayar langsung -- jadi pelanggan tahu persis kenapa internetnya mati dan bagaimana mengaktifkannya lagi.</p>
                        </div>

                        <h3 id="paket">4. Manajemen Paket Layanan & Voucher</h3>
                        <div class="step">
                            <h4>4.1 Paket Hotspot</h4>
                            <p>Konfigurasi paket hotspot dengan durasi dan bandwidth terbatas.</p>
                            <a class="btn btn-warning" href="packageshotspot.php" target="_blank"><i class="fas fa-wifi"></i> Setup Hotspot</a>
                        </div>
                        <div class="step">
                            <h4>4.2 Paket PPPoE</h4>
                            <p>Konfigurasi paket broadband dedicated dengan billing bulanan.</p>
                            <a class="btn btn-success" href="packages.php" target="_blank"><i class="fas fa-ethernet"></i> Setup PPPoE</a>
                        </div>
                        <div class="step">
                            <h4>4.3 Voucher Generator</h4>
                            <p>Generate voucher hotspot dengan kode unik untuk pencetakan. Cocok untuk dijual eceran (harian/mingguan) di warung, kos-kosan, atau kafe.</p>
                            <p><b>Simulasi:</b> Anda mau cetak 50 voucher hotspot 1 hari untuk dijual Rp 5.000/lembar. Buka Voucher Generator, pilih paket "1 Hari", isi jumlah 50, klik Generate -- sistem otomatis membuat 50 kode unik siap cetak/print.</p>
                            <a class="btn btn-info" href="vouchergenerator.php" target="_blank"><i class="fas fa-ticket-alt"></i> Generate Voucher</a>
                        </div>
                        <div class="step">
                            <h4>4.4 Editor Template Voucher</h4>
                            <p>Desain sendiri tampilan kertas voucher yang akan dicetak (logo, warna, ukuran, posisi kode) supaya sesuai branding usaha Anda, bukan cuma template polos bawaan sistem.</p>
                            <p><b>Simulasi:</b> Anda ingin voucher hotspot ada logo perusahaan di pojok kiri atas dan warna sesuai brand Anda (misal biru-putih). Buka Editor Template Voucher, upload logo, atur posisi teks kode & harga, simpan sebagai template baru -- template ini otomatis dipakai setiap kali generate voucher berikutnya.</p>
                            <a class="btn btn-info" href="voucher_template_builder.php" target="_blank"><i class="fas fa-object-group"></i> Edit Template Voucher</a>
                        </div>
                        <div class="step">
                            <h4>4.5 Voucher Bank</h4>
                            <p>"Bank" penyimpanan voucher yang sudah di-generate tapi belum terjual -- supaya Anda tahu stok voucher yang masih tersedia, mana yang sudah dijual/dicetak, dan bisa menghapus voucher yang rusak/salah cetak.</p>
                            <p><b>Simulasi:</b> Dari 50 voucher yang tadi digenerate, 30 sudah laku terjual minggu ini. Buka Voucher Bank untuk melihat sisa stok 20 voucher yang belum terpakai, sekaligus bisa cetak ulang atau hapus voucher yang kertasnya rusak sebelum sempat dijual.</p>
                            <a class="btn btn-info" href="voucherbank.php" target="_blank"><i class="fas fa-piggy-bank"></i> Buka Voucher Bank</a>
                        </div>

                        <h3 id="pelanggan">5. Manajemen Pelanggan</h3>
                        <div class="step">
                            <h4>5.1 Tambah Pelanggan PPPoE</h4>
                            <p>Daftarkan pelanggan baru dengan koneksi dedicated.</p>
                            <a class="btn btn-primary" href="tables.php" target="_blank"><i class="fas fa-user-plus"></i> Kelola PPPoE</a>
                        </div>
                        <div class="step">
                            <h4>5.2 Tambah Pelanggan Hotspot</h4>
                            <p>Kelola pelanggan hotspot berbasis voucher.</p>
                            <a class="btn btn-secondary" href="tableshotspot.php" target="_blank"><i class="fas fa-wifi"></i> Kelola Hotspot</a>
                        </div>
                        <div class="step">
                            <h4>5.3 Monitoring Real-time</h4>
                            <p>Pantau status koneksi, bandwidth usage, dan uptime pelanggan.</p>
                        </div>

                        <div class="step">
                            <h4>5.4 Provisioning Joblist -- Approval Instalasi Baru</h4>
                            <p>Kalau ada pendaftaran pelanggan baru dari sales/teknisi lapangan, permintaan itu tidak langsung aktif -- masuk dulu ke daftar "Provisioning Joblist" untuk disetujui (approve) oleh admin sebelum benar-benar dibuatkan akun PPPoE-nya di Mikrotik.</p>
                            <p><b>Simulasi:</b> Sales input calon pelanggan baru bernama Budi lewat form pendaftaran. Statusnya masuk sebagai "Menunggu Approval" di Provisioning Joblist. Admin cek kelengkapan datanya (alamat, paket, ODP tujuan), lalu klik <b>Approve</b> -- barulah akun Budi otomatis dibuat di Mikrotik dan dia bisa langsung internetan. Kalau data kurang lengkap/salah, admin bisa <b>Reject</b> dulu.</p>
                            <a class="btn btn-primary" href="provisioning_approval.php" target="_blank"><i class="fas fa-clipboard-check"></i> Buka Provisioning Joblist</a>
                        </div>

                        <div class="step">
                            <h4>5.5 Pelanggan Menunggak</h4>
                            <p>Daftar khusus pelanggan yang tagihannya sudah lewat jatuh tempo tapi belum bayar. Dari sini Anda bisa langsung broadcast pengingat pembayaran, buat tiket penagihan, atau kasih diskon massal ke banyak pelanggan sekaligus tanpa harus buka satu-satu.</p>
                            <p><b>Simulasi:</b> Awal bulan, ada 15 pelanggan yang belum bayar tagihan bulan lalu. Buka halaman Pelanggan Menunggak, centang semua 15 orang itu, lalu klik <b>Broadcast</b> untuk kirim pesan pengingat pembayaran sekaligus ke semuanya (via WA/Telegram/Email) -- tidak perlu kirim manual satu-satu.</p>
                            <a class="btn btn-warning" href="pelanggan_menunggak.php" target="_blank"><i class="fas fa-user-clock"></i> Buka Pelanggan Menunggak</a>
                        </div>

                        <div class="step">
                            <h4>5.6 Pelanggan Berhenti</h4>
                            <p>Daftar pelanggan yang sudah berhenti berlangganan (churn) -- baik karena pindah rumah, ganti provider, atau alasan lain. Data mereka tetap tersimpan (tidak dihapus permanen) untuk arsip, tapi tidak lagi ditagih atau dihitung sebagai pelanggan aktif.</p>
                            <p><b>Simulasi:</b> Pelanggan bernama Sari pindah kota dan minta berhenti langganan. Anda pindahkan datanya ke Pelanggan Berhenti (bukan dihapus) -- dia otomatis tidak lagi masuk hitungan pelanggan aktif/tagihan bulanan, tapi riwayat datanya masih bisa dicek kalau suatu saat dia daftar lagi.</p>
                            <a class="btn btn-secondary" href="daftar_pelanggan_berhenti.php" target="_blank"><i class="fas fa-user-slash"></i> Buka Pelanggan Berhenti</a>
                        </div>

                        <h3 id="pembayaran">6. Sistem Pembayaran & Billing</h3>
                        <div class="step">
                            <h4>6.1 Pilihan & Setup Metode Pembayaran</h4>
                            <ol>
                                <li>
                                    <b>Manual Transfer Bank</b><br>
                                    Pelanggan membayar ke rekening bank yang Anda daftarkan, lalu upload bukti transfer. Anda harus verifikasi manual sebelum layanan diaktifkan kembali.<br>
                                    <a class="btn btn-primary btn-sm mt-2" href="paymentset.php" target="_blank"><i class="fas fa-cog"></i> Setting Rekening Bank</a>
                                </li>
                                <li class="mt-3">
                                    <b>Payment Gateway Otomatis (8 pilihan)</b><br>
                                    Semua gateway di bawah diatur di halaman yang sama (<b>Payment Setting</b>) -- Anda boleh aktifkan lebih dari satu sekaligus, pelanggan akan melihat semua opsi yang aktif saat bayar. Untuk masing-masing, daftar dulu akun merchant di situs resminya, lalu isi API Key/kredensial yang diminta ke form-nya di sini:
                                    <ul>
                                        <li><b>Tripay</b> -- <a href="https://tripay.co.id/merchant/register" target="_blank">daftar merchant</a>, isi API Key/Private Key/Merchant Code. Mendukung VA, QRIS, e-wallet, retail (Alfamart/Indomaret).</li>
                                        <li><b>Duitku</b> -- <a href="https://duitku.com/register" target="_blank">daftar merchant</a>, isi Merchant Code & API Key. Mendukung VA & QRIS.</li>
                                        <li><b>Midtrans</b> -- daftar di dashboard Midtrans, isi Server Key & Client Key. Punya Snap (halaman checkout hosted) dengan banyak metode sekaligus.</li>
                                        <li><b>Xendit</b> -- daftar di dashboard Xendit, isi Server Key. Mendukung Invoice (link pembayaran hosted) dgn VA/QRIS/e-wallet.</li>
                                        <li><b>iPaymu</b> -- daftar akun iPaymu, isi Nomor VA & API Key.</li>
                                        <li><b>DOKU</b> -- daftar merchant DOKU, isi Client ID & Secret Key.</li>
                                        <li><b>Faspay</b> -- daftar merchant Faspay, isi Merchant ID/User ID/Password.</li>
                                        <li><b>DompetX</b> -- daftar akun DompetX, isi API Key (Secret Key opsional).</li>
                                    </ul>
                                    <a class="btn btn-warning btn-sm mt-2" href="paymentset.php" target="_blank"><i class="fas fa-credit-card"></i> Setting Payment Gateway</a>
                                </li>
                            </ol>
                            <div class="alert alert-info mt-3">
                                <b>Tips:</b> Anda bisa mengaktifkan lebih dari satu metode pembayaran sekaligus. Pelanggan akan melihat semua opsi yang aktif di halaman pembayarannya.
                            </div>
                        </div>

                        <div class="step">
                            <h4>6.2 Test Transaksi Simulasi -- Cek Gateway Sudah Benar Sebelum Dipakai Pelanggan</h4>
                            <p>Setelah isi API Key gateway, jangan langsung percaya begitu saja -- pakai tombol <b>Test Transaksi</b> di sebelah tiap gateway untuk mengecek kredensial Anda benar-benar tersambung ke gateway sebelum dipakai pelanggan sungguhan.</p>
                            <div class="alert alert-warning">
                                <b>Penting:</b> Test ini membuat transaksi <b>ASLI</b> nominal kecil (bisa Anda atur sendiri, defaultnya Rp 1.000) ke gateway sungguhan -- bukan sekadar simulasi di layar. Link/QR yang muncul valid dan bisa dibuka/dibayar siapa saja yang pegang link-nya, tapi transaksi test ini <b>tidak akan tercatat</b> di data transaksi/laporan pemasukan Anda.
                            </div>
                            <p><b>Simulasi:</b> Anda baru selesai isi API Key Tripay. Klik tombol <b>Test Transaksi</b> di kartu Tripay, pilih salah satu channel pembayaran (misal QRIS), lalu klik <b>Kirim Test</b>. Kalau berhasil, akan muncul link/QR pembayaran beneran -- artinya kredensial Anda sudah benar dan siap dipakai pelanggan. Kalau gagal, pesan error yang muncul membantu Anda tahu bagian mana yang salah (API Key keliru, format salah, dll) tanpa perlu menunggu laporan dari pelanggan yang gagal bayar.</p>
                        </div>

                        <div class="step">
                            <h4>6.3 Pengaturan Tanggal Jatuh Tempo (Fixed Due Date)</h4>
                            <p>Menentukan aturan kapan tagihan pelanggan jatuh tempo tiap bulannya -- bisa tanggal tetap sama untuk semua pelanggan (misal semua jatuh tempo tanggal 5), atau mengikuti tanggal pendaftaran/aktivasi masing-masing pelanggan (monthversary).</p>
                            <p><b>Simulasi:</b> Anda ingin semua pelanggan jatuh tempo tanggal 5 tiap bulan supaya mudah dipantau. Atur "Fixed Due Date" ke tanggal 5 -- sistem otomatis menghitung ulang jatuh tempo semua pelanggan (baik yang daftar tanggal 1 maupun tanggal 20) supaya jatuh tempo di tanggal 5 bulan berikutnya.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="notification.php" target="_blank"><i class="fas fa-calendar-alt"></i> Atur Jatuh Tempo</a>
                        </div>

                        <div class="step">
                            <h4>6.4 Diskon Pelanggan</h4>
                            <p>Memberi potongan harga ke pelanggan tertentu -- bisa permanen (selamanya) atau hanya untuk rentang periode tertentu (misal promo 3 bulan pertama).</p>
                            <p><b>Simulasi:</b> Pelanggan baru bernama Andi dapat promo diskon 20% untuk 3 bulan pertama saja. Buka menu Diskon, pilih Andi, isi persentase 20%, atur periode berlaku dari bulan ini sampai 3 bulan ke depan -- setelah 3 bulan, tagihan Andi otomatis kembali ke harga normal tanpa perlu diubah manual.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="diskon.php" target="_blank"><i class="fas fa-tags"></i> Kelola Diskon</a>
                        </div>

                        <div class="step">
                            <h4>6.5 Tambahan Biaya</h4>
                            <p>Kebalikan dari diskon -- menambahkan biaya ekstra ke tagihan pelanggan tertentu, misalnya biaya sewa perangkat tambahan, biaya pemasangan ulang, atau denda keterlambatan khusus.</p>
                            <p><b>Simulasi:</b> Pelanggan minta pasang access point tambahan dengan biaya sewa Rp 20.000/bulan. Buka menu Tambahan Biaya, pilih pelanggannya, isi nominal Rp 20.000 dengan keterangan "Sewa AP Tambahan" -- nominal ini otomatis ikut ditambahkan ke tagihan bulanannya.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="biaya_tambahan.php" target="_blank"><i class="fas fa-plus-circle"></i> Kelola Tambahan Biaya</a>
                        </div>

                        <div class="step">
                            <h4>6.6 Pengaturan Struk</h4>
                            <p>Mengatur tampilan struk/bukti pembayaran yang bisa dicetak atau diunduh PDF oleh pelanggan/admin setelah transaksi berhasil -- termasuk logo perusahaan, nama, alamat, dan format nomor struk.</p>
                            <p><b>Simulasi:</b> Anda ganti logo usaha. Upload logo baru di Pengaturan Struk -- setiap struk pembayaran yang dicetak/PDF setelah itu otomatis pakai logo baru, tanpa perlu ubah satu-satu.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="struk_setting.php" target="_blank"><i class="fas fa-receipt"></i> Atur Struk</a>
                        </div>

                        <div class="step">
                            <h4>6.7 Konfigurasi Billing Otomatis</h4>
                            <ol>
                                <li>Atur <b>harga paket</b> dan masa aktif di menu Paket (PPPoE/Hotspot).</li>
                                <li>Pastikan setiap pelanggan sudah memilih paket dan status aktif.</li>
                                <li>Tagihan akan otomatis dibuat setiap bulan (atau sesuai masa aktif paket).</li>
                                <li>Jika menggunakan payment gateway, sistem akan otomatis mendeteksi pembayaran dan mengaktifkan layanan.</li>
                                <li>Untuk pembayaran manual, Anda harus melakukan <b>verifikasi pembayaran</b> secara manual di menu pelanggan.</li>
                            </ol>
                            <a class="btn btn-success btn-sm mt-2" href="notification.php" target="_blank"><i class="fas fa-bell"></i> Setting Notifikasi Pembayaran</a>
                        </div>
                        <div class="step">
                            <h4>6.8 FAQ & Troubleshooting Pembayaran</h4>
                            <ul>
                                <li><b>Pembayaran tidak terdeteksi otomatis?</b><br>
                                    - Pastikan API Key, Merchant Code, dan callback URL sudah benar.<br>
                                    - Cek status transaksi di dashboard gateway Anda.<br>
                                    - Coba dulu tombol <b>Test Transaksi</b> (lihat 6.2) untuk pastikan kredensial benar sebelum curiga ke hal lain.<br>
                                </li>
                                <li class="mt-2"><b>Tagihan tidak muncul?</b><br>
                                    - Pastikan pelanggan sudah memilih paket dan status aktif.<br>
                                    - Cek pengaturan masa aktif paket & Fixed Due Date (lihat 6.3).</li>
                                <li class="mt-2"><b>Butuh bantuan lebih lanjut?</b><br>
                                    - Hubungi support CRM Billing atau konsultasikan ke grup komunitas.</li>
                            </ul>
                        </div>

                        <h3 id="komunikasi">7. Komunikasi & Notifikasi</h3>
                        <div class="step">
                            <h4>7.1 WhatsApp Bot (Auto Respon & Notifikasi)</h4>
                            <ol>
                                <li>
                                    <b>Fungsi WhatsApp Bot:</b><br>
                                    <ul>
                                        <li>Otomatis mengirim notifikasi pembayaran, jatuh tempo, dan status layanan ke pelanggan.</li>
                                        <li>Auto respon: pelanggan bisa cek tagihan, status koneksi, dan info lain via chat WhatsApp.</li>
                                        <li>Support broadcast info penting (misal: maintenance, promo, dsb).</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Cara Setup WhatsApp Bot:</b>
                                    <ul>
                                        <li>Buka menu <b>Setup WhatsApp Bot</b> di <a class="btn btn-success btn-sm" href="wabot.php" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp Bot</a></li>
                                        <li>Tambahkan bot baru, isi nama dan password bot.</li>
                                        <li>Ikuti petunjuk untuk scan QR dan mengaktifkan auto respon.</li>
                                        <li>Atur prompt/skrip auto respon sesuai kebutuhan bisnis Anda.</li>
                                    </ul>
                                </li>
                                <li class="mt-2">
                                    <b>Tips:</b>
                                    <ul>
                                        <li>Gunakan fitur <b>Edit Prompt</b> untuk menyesuaikan jawaban bot sesuai SOP bisnis Anda.</li>
                                        <li>Pastikan server bot tetap online agar notifikasi berjalan lancar.</li>
                                    </ul>
                                </li>
                            </ol>
                        </div>
                        <div class="step">
                            <h4>7.2 Telegram Bot</h4>
                            <p>Kanal notifikasi alternatif selain WhatsApp. Bedanya, bot Telegram jauh lebih mudah disiapkan -- tidak perlu scan QR atau nomor HP khusus, cukup 1 <b>Bot Token</b> dari @BotFather di aplikasi Telegram.</p>
                            <ol>
                                <li><b>Buat bot baru:</b> Chat ke <code>@BotFather</code> di Telegram, ketik <code>/newbot</code>, ikuti instruksinya sampai dapat Bot Token.</li>
                                <li><b>Daftarkan di sistem:</b> Buka menu <b>Telegram Bot</b>, klik Tambah Bot, tempel Bot Token-nya.</li>
                                <li><b>Pelanggan menghubungkan akun:</b> pelanggan klik link <code>t.me/nama_bot_anda?start=IDPEL_MEREKA</code> (linknya otomatis tersedia di halaman Telegram Bot) -- begitu pelanggan kirim <code>/start</code>, akun Telegram mereka otomatis tertaut ke data pelanggannya.</li>
                                <li><b>Kirim notifikasi:</b> centang kanal "Kirim juga via Telegram" saat broadcast/kirim reminder -- pesan otomatis terkirim ke pelanggan yang sudah tertaut.</li>
                            </ol>
                            <p><b>Simulasi:</b> Pelanggan bernama Rina ingin dapat notifikasi tagihan lewat Telegram (bukan WA). Dia buka link <code>t.me/BotAnda?start=RINA001</code>, klik Start di aplikasi Telegram-nya. Sejak saat itu, setiap kali Anda broadcast reminder pembayaran dengan kanal Telegram dicentang, Rina otomatis kebagian pesannya di Telegram, bukan cuma WA.</p>
                            <a class="btn btn-info btn-sm mt-2" href="telegrambot.php" target="_blank"><i class="fab fa-telegram"></i> Kelola Telegram Bot</a>
                        </div>

                        <div class="step">
                            <h4>7.3 Email SMTP</h4>
                            <p>Kanal notifikasi lewat email. Anda bisa pilih <b>Internal</b> (pakai email bawaan sistem, tidak perlu setting apa-apa, praktis buat langsung coba) atau <b>External</b> (pakai email Anda sendiri, misal Gmail, supaya nama pengirim & domainnya sesuai brand usaha Anda).</p>
                            <ol>
                                <li>Buka menu <b>Email SMTP</b> (hanya bisa diakses akun utama/owner, karena berisi kredensial sensitif).</li>
                                <li>Pilih mode <b>Internal</b> (langsung pakai, tidak perlu isi apapun) atau <b>External</b> (isi host/port/username/password email Anda -- untuk Gmail, pakai "App Password", bukan password login biasa).</li>
                                <li>Klik <b>Test Kirim</b> ke alamat email Anda sendiri dulu untuk memastikan settingnya benar, baru klik Simpan.</li>
                                <li>Ada juga tombol <b>Cek Inbox</b> untuk melihat email masuk ke akun tersebut langsung dari halaman ini, tanpa perlu buka aplikasi email terpisah.</li>
                            </ol>
                            <p><b>Simulasi:</b> Anda mau kirim tagihan lewat email dari alamat resmi usaha (<code>tagihan@usahaanda.com</code>). Pilih mode External, isi host SMTP dari penyedia email Anda beserta usernamenya, klik Test Kirim ke email pribadi Anda -- kalau masuk, simpan. Sekarang saat broadcast, centang kanal "Kirim juga via Email" dan pesan otomatis terkirim ke email pelanggan yang datanya terisi.</p>
                            <a class="btn btn-info btn-sm mt-2" href="email_setting.php" target="_blank"><i class="fas fa-envelope"></i> Atur Email SMTP</a>
                        </div>

                        <div class="step">
                            <h4>7.4 Live Chat</h4>
                            <p>Ruang obrolan langsung antara admin/tim CS dengan pelanggan, mirip fitur chat customer service di aplikasi lain. Pesan yang masuk dari pelanggan (misal lewat WA bot) bisa dibalas langsung dari sini tanpa harus buka WhatsApp di HP.</p>
                            <p><b>Simulasi:</b> Pelanggan chat via WA bot "kenapa internet saya lambat?". Pesan itu otomatis muncul di halaman Live Chat sistem. Admin bisa balas dari komputer langsung dari sini, dan balasannya terkirim balik ke WhatsApp pelanggan -- semua riwayat obrolan tersimpan rapi per pelanggan.</p>
                            <a class="btn btn-info btn-sm mt-2" href="livechat.php" target="_blank"><i class="fas fa-comments"></i> Buka Live Chat</a>
                        </div>

                        <div class="step">
                            <h4>7.5 Broadcast Info (Kirim Pesan Massal)</h4>
                            <p>Kirim pesan yang sama ke banyak pelanggan sekaligus -- misalnya info gangguan jaringan, jadwal maintenance, atau promo. Bisa target semua pelanggan, per Area, atau per ODP/ODC tertentu saja (jadi hanya pelanggan yang benar-benar terdampak yang dikirimi, tidak semua pelanggan diganggu notifikasi yang tidak relevan).</p>
                            <p><b>Simulasi:</b> Ada gangguan kabel putus yang hanya berdampak ke pelanggan di ODC "Cilangkap-03". Buka Broadcast Info, pilih target ODC tersebut (bukan "Semua Pelanggan"), tulis pesan "Mohon maaf sedang ada perbaikan jaringan, estimasi selesai 2 jam", pilih kanal (WA/Telegram/Email), lalu Kirim -- hanya pelanggan di ODC itu yang menerima pesan, pelanggan area lain tidak terganggu notifikasi yang tidak relevan buat mereka.</p>
                            <a class="btn btn-info btn-sm mt-2" href="broadcast.php" target="_blank"><i class="fas fa-bullhorn"></i> Buka Broadcast Info</a>
                        </div>

                        <div class="step">
                            <h4>7.6 Notifikasi Otomatis Sistem</h4>
                            <ol>
                                <li>
                                    <b>Reminder Pembayaran Jatuh Tempo</b><br>
                                    Pelanggan akan menerima pesan otomatis sebelum dan saat tagihan jatuh tempo, berisi info tagihan, jatuh tempo, dan link pembayaran.
                                    <div class="code-block mt-2">
                                        Contoh:<br>
                                        <span style="color:#00a000">⏰ Pengingat Pembayaran: Tagihan Anda jatuh tempo pada <b>30/10/2025</b>. Silakan bayar sebelum tanggal tersebut agar layanan tetap aktif. <br>Link: [Portal Pembayaran]</span>
                                    </div>
                                </li>
                                <li class="mt-3">
                                    <b>Notifikasi Koneksi Offline</b><br>
                                    Sistem akan mengirim pesan otomatis jika koneksi pelanggan terputus dari server.
                                    <div class="code-block mt-2">
                                        Contoh:<br>
                                        <span style="color:#00a000">⚠️ Koneksi Anda terputus. Silakan cek perangkat atau hubungi admin jika butuh bantuan.</span>
                                    </div>
                                </li>
                                <li class="mt-3">
                                    <b>Konfirmasi Pembayaran Berhasil</b><br>
                                    Setelah pembayaran terverifikasi, pelanggan langsung mendapat notifikasi layanan aktif kembali.
                                    <div class="code-block mt-2">
                                        Contoh:<br>
                                        <span style="color:#00a000">✅ Pembayaran Anda telah diterima. Layanan sudah aktif kembali. Terima kasih!</span>
                                    </div>
                                </li>
                                <li class="mt-3">
                                    <b>Alert Maintenance Jaringan</b><br>
                                    Admin dapat mengirim info massal ke semua pelanggan saat ada gangguan/maintenance.
                                    <div class="code-block mt-2">
                                        Contoh:<br>
                                        <span style="color:#00a000">🔧 Info Maintenance: Akan ada pemeliharaan jaringan pada 31/10/2025 pukul 01.00-03.00 WIB. Mohon maaf atas ketidaknyamanannya.</span>
                                    </div>
                                </li>
                            </ol>
                            <div class="alert alert-info mt-3">
                                <b>Tips Pengaturan:</b><br>
                                - Semua notifikasi bisa dikustomisasi melalui menu <b>Setup WhatsApp Bot</b> dan <b>Setting Notifikasi</b>.<br>
                                - Anda dapat mengatur jadwal pengiriman reminder dan isi pesan sesuai kebutuhan.<br>
                                - Pastikan server WhatsApp Bot selalu online agar notifikasi berjalan otomatis.<br>
                                - Cek menu <b>Notifikasi</b> untuk melihat status dan riwayat pengiriman pesan.
                            </div>
                        </div>

                        <h3 id="monitoring">8. Monitoring & Laporan</h3>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="border p-3 mb-3">
                                    <h5><i class="fas fa-tachometer-alt text-success"></i> Dashboard Real-time</h5>
                                    <ul>
                                        <li>Status server dan perangkat jaringan</li>
                                        <li>Grafik penggunaan bandwidth</li>
                                        <li>Statistik pelanggan aktif</li>
                                        <li>Tracking revenue</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border p-3 mb-3">
                                    <h5><i class="fas fa-file-alt text-info"></i> Laporan Detail</h5>
                                    <ul>
                                        <li>Laporan keuangan bulanan</li>
                                        <li>Usage report per pelanggan</li>
                                        <li>Network performance report</li>
                                        <li>Customer analytics</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="step">
                            <h4>8.1 Transaksi (Transaction)</h4>
                            <p>Daftar lengkap semua transaksi pembayaran yang pernah terjadi -- baik dari payment gateway otomatis maupun verifikasi manual. Dari sini Anda bisa cari transaksi tertentu, cetak/download struk, generate invoice manual, atau export ke Excel/PDF untuk laporan.</p>
                            <p><b>Simulasi:</b> Ada pelanggan komplain "saya sudah bayar tapi internet belum aktif". Buka menu Transaksi, cari nama/IDPEL pelanggan tersebut, cek apakah transaksinya benar-benar tercatat "Berhasil" atau masih "Permintaan Kode" (belum benar-benar dibayar) -- dari situ Anda tahu apakah ini masalah sistem atau pelanggan belum benar-benar menyelesaikan pembayaran.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="Transaction.php" target="_blank"><i class="fas fa-exchange-alt"></i> Buka Transaksi</a>
                        </div>

                        <div class="step">
                            <h4>8.2 Laporan Pengeluaran</h4>
                            <p>Catat pengeluaran operasional usaha Anda (bayar listrik, sewa tempat, gaji teknisi, dll) supaya laporan Neraca Keuangan di halaman Statistik jadi akurat (pemasukan dikurangi pengeluaran, bukan cuma pemasukan saja).</p>
                            <p><b>Simulasi:</b> Anda bayar tagihan listrik kantor Rp 500.000. Catat di Laporan Pengeluaran dengan kategori "Listrik" -- otomatis ikut mengurangi angka di Neraca Keuangan bulan ini, jadi laporan untung-rugi usaha Anda lebih akurat, bukan cuma menampilkan pemasukan kotor.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="pengeluaran.php" target="_blank"><i class="fas fa-chart-bar"></i> Buka Laporan Pengeluaran</a>
                        </div>

                        <h3 id="tim">9. Tim, Sales/Mitra & Hak Akses ASSISTANT</h3>
                        <div class="step">
                            <h4>9.1 Sales / Mitra Accounts</h4>
                            <p>Kelola akun sales/mitra reseller yang bantu jualan paket internet Anda. Tiap mitra bisa dibatasi hanya boleh lihat/jual paket & harga tertentu (misal harga khusus reseller, bukan harga umum), sehingga mitra tidak bisa lihat semua data pelanggan Anda.</p>
                            <p><b>Simulasi:</b> Anda punya mitra bernama Toko Jaya yang jualan paket internet ke tetangganya. Buat akun mitra untuk Toko Jaya, batasi cuma bisa lihat paket "Rumahan 10Mbps" dengan harga khusus reseller (lebih murah dari harga umum) -- Toko Jaya bisa jual paket itu dengan margin keuntungan sendiri, tanpa lihat data pelanggan/paket lain milik Anda.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="mitraadmin.php" target="_blank"><i class="fas fa-handshake"></i> Kelola Sales/Mitra</a>
                        </div>
                        <div class="step">
                            <h4>9.2 Commission Setting -- Aturan Komisi</h4>
                            <p>Menentukan berapa persen/nominal komisi yang didapat sales/mitra dari tiap pelanggan yang berhasil mereka bawa, terpisah untuk paket PPPoE dan Hotspot.</p>
                            <p><b>Simulasi:</b> Anda ingin beri komisi 10% dari harga paket untuk tiap pelanggan baru yang dibawa sales. Atur di Commission Setting: PPPoE 10%. Setiap kali pelanggan yang terdaftar atas nama sales tertentu bayar, sistem otomatis menghitung 10% dari nilainya sebagai komisi sales itu.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="commissionsetting.php" target="_blank"><i class="fas fa-percentage"></i> Atur Komisi</a>
                        </div>
                        <div class="step">
                            <h4>9.3 Pembayaran Komisi</h4>
                            <p>Rekap total komisi yang harus dibayarkan ke tiap sales/mitra dalam periode tertentu, dan tempat mencatat/meng-ACC kalau komisinya sudah dibayarkan.</p>
                            <p><b>Simulasi:</b> Akhir bulan, Anda mau bayar komisi ke semua sales. Buka Pembayaran Komisi, lihat rekap otomatis berapa komisi tiap sales bulan ini (berdasarkan aturan di 9.2), transfer ke masing-masing, lalu klik <b>ACC</b> supaya tercatat sudah lunas dan tidak tertagih dobel bulan depan.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="rekappembayaranmitra.php" target="_blank"><i class="fas fa-percentage"></i> Buka Pembayaran Komisi</a>
                        </div>
                        <div class="step">
                            <h4>9.4 Hak Akses Menu untuk Akun ASSISTANT (Staff/Karyawan)</h4>
                            <p>Kalau Anda punya karyawan/staff yang bantu operasional (CS, teknisi, admin keuangan), buatkan akun bertipe <b>ASSISTANT</b> lewat menu <b>Profile and Account</b> -- lalu atur PERSIS menu dan tombol apa saja yang boleh mereka lihat/pakai, supaya staff CS misalnya tidak bisa buka Payment Setting atau hapus data pelanggan.</p>
                            <ol>
                                <li><b>Hak Akses Menu</b>: centang halaman mana saja yang boleh dibuka staff ini (dikelompokkan per kategori supaya mudah dicari).</li>
                                <li><b>Card Dashboard ASSISTANT</b>: atur ringkasan/card apa saja yang tampil di dashboard mereka.</li>
                                <li><b>Group Tombol per Halaman</b> & <b>Tombol Individual</b>: atur lebih detail lagi, sampai level tombol spesifik dalam satu halaman (misal staff CS boleh buka halaman Customer PPPOE tapi tombol "Hapus Pelanggan"-nya disembunyikan) -- daftar tombol ini juga sudah dikelompokkan per halaman asalnya supaya gampang dicari.</li>
                            </ol>
                            <p><b>Simulasi:</b> Anda rekrut CS baru bernama Dewi yang tugasnya cuma balas chat pelanggan dan cek status pembayaran, tidak boleh utak-atik setting sensitif. Buat akun ASSISTANT untuk Dewi, centang akses ke menu Live Chat, Customer PPPOE, dan Pelanggan Menunggak saja -- lalu di bagian Tombol Individual, matikan tombol "Hapus Pelanggan" dan "Export Data" supaya Dewi cuma bisa lihat & balas chat, tidak bisa menghapus atau mengekspor data sensitif.</p>
                            <div class="alert alert-info mt-2">
                                <b>Tips:</b> Kalau bingung banyaknya opsi, mulai dari yang longgar dulu (centang banyak), lalu kurangi pelan-pelan sambil amati staff itu benar-benar pakai menu apa saja dalam seminggu pertama kerja.
                            </div>
                            <a class="btn btn-primary btn-sm mt-2" href="user.php" target="_blank"><i class="fas fa-user-cog"></i> Kelola Akun & Hak Akses</a>
                        </div>

                        <h3 id="sistem">10. Pengaturan Sistem & Portal Pelanggan</h3>
                        <div class="step">
                            <h4>10.1 System Setting</h4>
                            <p>Pengaturan tingkat sistem, termasuk jadwal otomatis (cron) untuk membuat tiket "Dismantle" (pencabutan layanan pelanggan yang lama menunggak tanpa bayar) dan tiket "Maintenance No-Payment" -- supaya Anda tidak perlu ingat-ingat manual kapan harus tindak lanjuti pelanggan yang sudah lama tidak bayar.</p>
                            <p><b>Simulasi:</b> Anda ingin pelanggan yang menunggak lebih dari 60 hari otomatis dibuatkan tiket "Dismantle" supaya tim lapangan tahu harus cabut perangkatnya. Atur jadwal cron itu di System Setting -- setiap hari sistem otomatis cek dan bikin tiket buat pelanggan yang memenuhi syarat, tanpa Anda perlu cek manual satu-satu tiap hari.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="system_setting.php" target="_blank"><i class="fas fa-cogs"></i> Buka System Setting</a>
                        </div>
                        <div class="step">
                            <h4>10.2 Pengaturan Halaman Pelanggan (Portal Setting)</h4>
                            <p>Mengatur isi tombol/link yang muncul di halaman login portal pelanggan Anda -- seperti FAQ (pertanyaan umum), Kebijakan Refund, Syarat & Ketentuan, dan Kontak bantuan. Ini yang dilihat pelanggan pertama kali saat mereka buka portal pembayaran online.</p>
                            <p><b>Simulasi:</b> Banyak pelanggan baru bingung cara bayar pertama kali. Isi bagian FAQ di Portal Setting dengan pertanyaan umum seperti "Bagaimana cara bayar?" beserta jawabannya -- pelanggan bisa baca sendiri tanpa perlu chat CS dulu, mengurangi beban tim CS Anda.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="portal_setting.php" target="_blank"><i class="fas fa-file-contract"></i> Atur Halaman Pelanggan</a>
                        </div>
                        <div class="step">
                            <h4>10.3 Portal Pelanggan (Sisi Pelanggan)</h4>
                            <p>Ini halaman yang dipakai PELANGGAN Anda sendiri (bukan admin) untuk login, cek tagihan, dan bayar online tanpa harus chat admin. Bagikan link portal ini ke pelanggan Anda (tersedia juga di menu <b>LINK ANDA</b> di sidebar untuk disalin cepat).</p>
                            <p><b>Simulasi:</b> Pelanggan bernama Joko mau cek tagihan tanpa nunggu balasan admin. Dia buka link portal pelanggan Anda, login pakai nomor HP/IDPEL-nya, langsung lihat tagihan bulan ini beserta tombol Bayar Sekarang yang bisa langsung diklik untuk bayar via payment gateway yang sudah Anda aktifkan -- semua tanpa perlu menghubungi Anda sama sekali.</p>
                        </div>
                        <div class="step">
                            <h4>10.4 Backup & Restore</h4>
                            <p>Buat cadangan (backup) seluruh data sistem Anda sewaktu-waktu, dan bisa dikembalikan (restore) kalau terjadi hal tak diinginkan (data terhapus tidak sengaja, dll).</p>
                            <p><b>Simulasi:</b> Sebelum Anda mencoba fitur baru yang belum familiar, download backup data dulu di menu ini sebagai jaring pengaman -- kalau ada yang tidak sesuai harapan, Anda punya cadangan untuk restore kembali ke kondisi sebelumnya.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="backup_restore.php" target="_blank"><i class="fas fa-database"></i> Buka Backup & Restore</a>
                        </div>
                        <div class="step">
                            <h4>10.5 API Integrasi</h4>
                            <p>Kalau Anda ingin menghubungkan sistem billing ini ke aplikasi lain (misal aplikasi mobile custom buatan Anda sendiri), di sini tempat generate API Key dan atur modul API mana saja yang diizinkan diakses dari luar.</p>
                            <p><b>Simulasi:</b> Tim developer Anda mau bikin aplikasi mobile terpisah yang menampilkan data pelanggan dari sistem ini. Generate API Key di menu ini, berikan ke developer -- mereka pakai key itu untuk mengambil data lewat API tanpa perlu akses langsung ke database Anda.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="settingsapi.php" target="_blank"><i class="fas fa-plug"></i> Buka API Integrasi</a>
                        </div>
                        <div class="step">
                            <h4>10.6 Log History</h4>
                            <p>Catatan riwayat aktivitas penting di sistem (siapa mengubah apa, kapan) -- berguna untuk audit kalau ada perubahan data yang tidak terduga dan Anda ingin tahu siapa pelakunya.</p>
                            <p><b>Simulasi:</b> Ada harga paket yang tiba-tiba berubah tanpa Anda sadari. Cek Log History untuk lihat siapa (akun mana) yang mengubah harga paket itu dan kapan -- membantu melacak apakah itu kesalahan staff atau memang perubahan yang Anda minta sebelumnya.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="log_history.php" target="_blank"><i class="fas fa-history"></i> Buka Log History</a>
                        </div>
                        <div class="step">
                            <h4>10.7 Informasi Server ACS</h4>
                            <p>Halaman info untuk integrasi ACS (Auto Configuration Server, biasa dipakai untuk kelola modem/ONT pelanggan dari jarak jauh) -- kalau Anda menyewa/pakai server ACS terpisah, di sini tempat lihat detail koneksinya.</p>
                            <a class="btn btn-primary btn-sm mt-2" href="acs_server_info.php" target="_blank"><i class="fas fa-info-circle"></i> Lihat Info Server ACS</a>
                        </div>

                        <h3 id="tiket">11. Tiket & Provisioning</h3>
                        <div class="step">
                            <h4>11.1 Manajer Tiket / Pemantauan Tiket</h4>
                            <p>Sistem tiket untuk mencatat & menindaklanjuti keluhan/pekerjaan lapangan (gangguan internet, pemasangan baru, dismantle, dll), supaya tidak ada laporan pelanggan yang "hilang" atau lupa ditindaklanjuti. Sistem ini punya 2 mode tampilan (cuma salah satu yang aktif, diatur di System Setting) -- <b>Manajer Tiket</b> (tampilan sederhana) atau <b>Pemantauan Tiket</b> (tampilan lebih detail/monitoring). Jangan bingung kalau menu yang tampil di sidebar Anda cuma salah satu, itu memang sengaja.</p>
                            <p><b>Simulasi:</b> Pelanggan lapor "internet mati total sejak semalam". Buat tiket baru dengan tipe "Gangguan", assign ke teknisi yang bertugas di area itu. Teknisi update statusnya jadi "Proses" saat berangkat ke lokasi, lalu "Selesai" setelah masalah teratasi -- semua riwayat tercatat, jadi kalau pelanggan yang sama komplain lagi minggu depan, Anda bisa cek riwayat gangguan sebelumnya.</p>
                        </div>
                        <div class="step">
                            <h4>11.2 Provisioning Joblist</h4>
                            <p>Lihat penjelasan lengkap di bagian <a href="#pelanggan">5.4 Provisioning Joblist</a> -- proses approval pendaftaran pelanggan baru sebelum akunnya benar-benar aktif di Mikrotik.</p>
                        </div>

                        <h3 id="troubleshoot">12. Troubleshooting & FAQ</h3>
                        <div class="accordion" id="troubleshootAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#trouble1">
                                        Server Mikrotik tidak terkoneksi
                                    </button>
                                </h2>
                                <div id="trouble1" class="accordion-collapse collapse show" data-bs-parent="#troubleshootAccordion">
                                    <div class="accordion-body">
                                        <ol>
                                            <li>Periksa koneksi VPN</li>
                                            <li>Aktifkan API service: <code>/ip service set api disabled=no</code></li>
                                            <li>Verifikasi username/password API</li>
                                            <li>Cek firewall rules</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#trouble2">
                                        Payment gateway error
                                    </button>
                                </h2>
                                <div id="trouble2" class="accordion-collapse collapse" data-bs-parent="#troubleshootAccordion">
                                    <div class="accordion-body">
                                        <ol>
                                            <li>Periksa API key dan merchant code</li>
                                            <li>Pastikan webhook URL accessible</li>
                                            <li>Verifikasi signature validation</li>
                                            <li>Cek log error di Payment Settings</li>
                                            <li>Coba dulu tombol <b>Test Transaksi</b> di halaman Payment Setting untuk memastikan kredensial memang benar sebelum curiga ke sebab lain.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#trouble3">
                                        Pelanggan tidak menerima notifikasi Telegram/Email
                                    </button>
                                </h2>
                                <div id="trouble3" class="accordion-collapse collapse" data-bs-parent="#troubleshootAccordion">
                                    <div class="accordion-body">
                                        <ol>
                                            <li><b>Telegram:</b> pastikan pelanggan sudah pernah klik link <code>t.me/nama_bot?start=IDPEL</code> dan kirim <code>/start</code> -- kalau belum, chat_id mereka belum tertaut dan sistem tidak tahu harus kirim ke mana.</li>
                                            <li><b>Email:</b> pastikan kolom Email di data pelanggan sudah terisi, dan sudah coba <b>Test Kirim</b> di menu Email SMTP -- kalau test gagal, cek folder Spam/Junk email tujuan.</li>
                                            <li>Pastikan kanal yang dituju (Telegram/Email) memang dicentang saat broadcast/kirim reminder -- default sistem hanya kirim WA kecuali dicentang manual.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#trouble4">
                                        Staff ASSISTANT tidak bisa buka halaman/tombol tertentu
                                    </button>
                                </h2>
                                <div id="trouble4" class="accordion-collapse collapse" data-bs-parent="#troubleshootAccordion">
                                    <div class="accordion-body">
                                        <ol>
                                            <li>Login sebagai akun utama (owner), buka menu <b>Profile and Account</b>.</li>
                                            <li>Edit akun ASSISTANT yang bermasalah, cek bagian <b>Hak Akses Menu</b> -- pastikan halaman yang dibutuhkan sudah dicentang.</li>
                                            <li>Kalau halamannya sudah tercentang tapi tombol tertentu masih hilang, cek juga bagian <b>Group Tombol per Halaman</b> dan <b>Tombol Individual</b>.</li>
                                            <li>Beberapa halaman sensitif (Payment Setting, Email SMTP) memang sengaja dikunci total untuk ASSISTANT dan tidak bisa diaktifkan lewat centang apapun -- ini bukan bug.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#trouble5">
                                        Pendaftaran pelanggan baru tidak langsung aktif
                                    </button>
                                </h2>
                                <div id="trouble5" class="accordion-collapse collapse" data-bs-parent="#troubleshootAccordion">
                                    <div class="accordion-body">
                                        <ol>
                                            <li>Ini biasanya bukan error -- cek dulu menu <b>Provisioning Joblist</b>, kemungkinan pendaftarannya masih berstatus "Menunggu Approval".</li>
                                            <li>Klik <b>Approve</b> pada pendaftaran yang datanya sudah benar supaya akun pelanggan otomatis dibuat di Mikrotik.</li>
                                            <li>Kalau memang bukan lewat Provisioning Joblist (input langsung), pastikan ODP/Server/Paket yang dipilih sudah benar dan tersedia slot IP-nya di Pool.</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- Panduan Statistik & Laporan Pembayaran -->
                        <div class="step mt-5">
                            <h4><i class="fas fa-chart-bar"></i> Panduan Statistik & Laporan Pembayaran</h4>
                            <ol>
                                <li>
                                    <b>Statistik Ringkasan</b><br>
                                    Lihat jumlah pelanggan aktif, sudah bayar, belum bayar, jatuh tempo, nunggak, dan pemasukan (hari, minggu, bulan, tahun) di bagian atas halaman statistik. Gunakan filter bulan/tahun untuk menyesuaikan data.
                                </li>
                                <li class="mt-2">
                                    <b>Neraca Keuangan</b><br>
                                    Tampilkan perbandingan pemasukan dan pengeluaran secara otomatis. Pastikan Anda mencatat pengeluaran di menu pengeluaran agar neraca akurat.
                                </li>
                                <li class="mt-2">
                                    <b>Laporan Pajak UMKM/PT</b><br>
                                    Sistem menghitung estimasi PPH Final 0.5% dari omzet bulanan/tahunan secara otomatis. Data ini bisa digunakan untuk pelaporan pajak.
                                </li>
                                <li class="mt-2">
                                    <b>Statistik Pendapatan Per Periode</b><br>
                                    Pilih periode (bulanan/tahunan) untuk melihat grafik dan tabel pendapatan. Cocok untuk analisa tren bisnis.
                                </li>
                                <li class="mt-2">
                                    <b>Laporan Paket Paling Banyak Terjual</b><br>
                                    Daftar 10 paket layanan yang paling sering dibeli pelanggan. Gunakan data ini untuk strategi promosi.
                                </li>
                                <li class="mt-2">
                                    <b>Pelanggan Menunggak, FASUM, dan Promo</b><br>
                                    Tabel pelanggan yang menunggak, pelanggan FASUM (harga 0), dan pelanggan dalam promo. Data ini membantu penagihan dan evaluasi program CSR/promo.
                                </li>
                                <li class="mt-2">
                                    <b>Statistik Sales</b><br>
                                    Lihat daftar sales dengan jumlah pelanggan paling banyak bayar dan menunggak, serta paket yang dijual. Cocok untuk monitoring kinerja sales.
                                </li>
                            </ol>
                            <div class="alert alert-info mt-3">
                                <b>Tips:</b><br>
                                - Gunakan filter bulan/tahun untuk analisa spesifik.<br>
                                - Klik tombol refresh jika data tampak belum update.<br>
                                - Data pada tabel dapat diurutkan dan dicari dengan fitur DataTables.<br>
                                - Untuk detail pemasukan/pengeluaran, cek menu terkait di sidebar.
                            </div>
                        </div>


                        <!-- Alur Kerja Otomatisasi Tagihan & Isolir Layanan -->
                        <div class="step mt-5">
                            <h4><i class="fas fa-cogs"></i> Alur Kerja Otomatisasi Tagihan & Isolir Layanan</h4>
                            <ol>
                                <li>
                                    <b>Pembuatan Tagihan Otomatis</b><br>
                                    Sistem akan membuat tagihan baru secara otomatis setiap bulan (atau sesuai masa aktif paket) untuk semua pelanggan aktif. Anda tidak perlu membuat tagihan manual satu per satu.
                                </li>
                                <li class="mt-2">
                                    <b>Pengiriman Notifikasi Otomatis</b><br>
                                    Pelanggan akan menerima notifikasi WhatsApp dan email secara otomatis sebelum jatuh tempo, saat jatuh tempo, dan jika ada gangguan jaringan. Notifikasi ini dikirim oleh sistem sesuai jadwal yang sudah diatur.
                                </li>
                                <li class="mt-2">
                                    <b>Isolir Otomatis Pelanggan Menunggak</b><br>
                                    Jika pelanggan belum membayar hingga melewati jatuh tempo, sistem akan otomatis menonaktifkan layanan (isolir) sesuai aturan yang berlaku. Setelah pembayaran diterima, layanan akan aktif kembali secara otomatis.
                                </li>
                                <li class="mt-2">
                                    <b>Penghapusan Kode Pembayaran Expired</b><br>
                                    Kode pembayaran yang sudah kadaluarsa akan dihapus otomatis oleh sistem, sehingga data tetap rapi dan tidak menumpuk.
                                </li>
                                <li class="mt-2">
                                    <b>Broadcast Info Gangguan/Maintenance</b><br>
                                    Admin dapat mengirim info massal ke semua pelanggan melalui menu broadcast, misal saat ada maintenance atau gangguan layanan.
                                </li>
                            </ol>
                            <div class="alert alert-info mt-3">
                                <b>Catatan untuk Pengguna:</b><br>
                                - Semua proses di atas berjalan otomatis, Anda cukup memantau status di dashboard.<br>
                                - Pastikan data pelanggan, paket, dan jadwal sudah benar agar otomatisasi berjalan lancar.<br>
                                - Jika ada kendala (misal notifikasi tidak terkirim), cek status WhatsApp Bot dan koneksi server.<br>
                                - Untuk kebutuhan khusus, Anda bisa mengatur ulang jadwal atau isi pesan notifikasi di menu pengaturan.<br>
                            </div>
                        </div>

                        <div class="alert alert-success mt-4">
                            <h4><i class="fas fa-check-circle"></i> Sistem Siap Digunakan!</h4>
                            <p>Selamat! Konfigurasi CRM Billing System telah selesai. Sistem Anda sekarang siap untuk mengelola bisnis provider internet secara profesional.</p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>



    </div>
    </main>

    <!--   Core JS Files   -->
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script>
        var win = navigator.platform.indexOf('Win') > -1;
        if (win && document.querySelector('#sidenav-scrollbar')) {
            var options = {
                damping: '0.5'
            }
            Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Github buttons -->
    <!-- Tambahkan Bootstrap JS (wajib untuk modal) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script async defer src="https://buttons.github.io/buttons.js"></script>
    <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
    <script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
</body>

<script>
    window.addEventListener("load", function() {
        document.getElementById("loading").style.display = "none";
        document.getElementById("content").style.display = "block";
    });
</script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const navLinks = document.querySelectorAll(".nav-link");

        navLinks.forEach((link) => {
            // Event ketika mouse masuk (hover)
            link.addEventListener("mouseenter", function() {
                this.classList.add("active");
            });

            // Event ketika mouse keluar
            link.addEventListener("mouseleave", function() {
                this.classList.remove("active");
            });

            // Event ketika link diklik
            link.addEventListener("click", function(event) {
                // Hapus active dari semua link
                navLinks.forEach((el) => el.classList.remove("active"));

                // Tambahkan active ke yang diklik
                this.classList.add("active");

                // Jalankan navigasi ke href
                const href = this.getAttribute("href");
                if (href && href !== "#") {
                    window.location.href = href;
                }
            });
        });
    });
</script>

</html>