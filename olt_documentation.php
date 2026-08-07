<?php require 'header.php'; ?>

<style>
.doc-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem 0;
    margin-bottom: 2rem;
}
.feature-card {
    border: 1px solid #e3e6f0;
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
    background: white;
}
.feature-card:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    transform: translateY(-2px);
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
.status-badge {
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}
.status-online { background: #d4edda; color: #155724; }
.status-offline { background: #f8d7da; color: #721c24; }
.status-warning { background: #fff3cd; color: #856404; }
.power-good { color: #28a745; font-weight: bold; }
.power-warning { color: #ffc107; font-weight: bold; }
.power-danger { color: #dc3545; font-weight: bold; }
.nav-pills .nav-link.active {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white !important;
    font-weight: 600;
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
}
.nav-pills .nav-link {
    color: #2c3e50;
    font-weight: 600;
    padding: 0.8rem 1.2rem;
    margin-bottom: 0.4rem;
    border-radius: 10px;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    background: #f8f9fa;
}
.nav-pills .nav-link:hover {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white !important;
    border-color: #007bff;
    transform: translateX(5px);
    box-shadow: 0 3px 10px rgba(0, 123, 255, 0.3);
}
.toc {
    background: linear-gradient(135deg, #ffffff, #f8f9fa);
    border: 2px solid #e9ecef;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.toc h5 {
    color: #2c3e50;
    font-weight: 700;
    margin-bottom: 1.5rem;
    padding-bottom: 0.8rem;
    border-bottom: 3px solid #28a745;
    font-size: 1.3rem;
}
.flow-step {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 1rem;
    margin: 0.5rem 0;
    border-radius: 0 5px 5px 0;
}
.screenshot-placeholder {
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    padding: 3rem;
    text-align: center;
    border-radius: 10px;
    margin: 1rem 0;
    color: #6c757d;
}

/* Print styles: compact, A4, hide interactive elements */
@media print {
    @page { size: A4; margin: 15mm; }
    html, body { width: 210mm; height: 297mm; }

    /* General typography and spacing compact */
    body { font-size: 12px; line-height: 1.15; color: #000 !important; background: #fff !important; }
    .feature-card, .card { background: #fff !important; color: #000 !important; }
    .container, .container-fluid { width: auto !important; padding: 0; }
    h1 { font-size: 18px; margin: 0 0 6px 0; }
    h2 { font-size: 14px; margin: 8px 0; }
    h5, h6 { font-size: 12px; margin: 6px 0; }
    p, li, td, th { font-size: 11px; }

    /* Compact cards */
    .feature-card, .toc, .card { border: none !important; box-shadow: none !important; padding: 6px !important; margin: 4px 0 !important; }

    /* Tables: smaller and condensed */
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    table th, table td { padding: 4px 6px; border: 1px solid #444; }
    .table-sm th, .table-sm td { padding: 3px 5px; }


    /* Hide only navigation/sidebar/tombol, not main content */
    .navbar, header, .main-header, .site-header { display: none !important; }
    /* Hanya sidebar TOC dan tombol yang disembunyikan, bukan semua .nav */
    .toc, .toc .nav, .btn, .badge, .accordion-button, .screenshot-placeholder { display: none !important; }

    /* Hide decorative icons to reduce noise */
    .doc-header i, h1 i, h2 i, h5 i, h6 i, .feature-card i { display: none !important; }
    .doc-header { background: none !important; color: #000 !important; padding: 6px 0 !important; margin-bottom: 6px !important; }

    /* Ensure page-break rules for sections */
    section { page-break-inside: avoid; }
    .page-break { page-break-before: always; }

    /* Make lists more compact */
    ul, ol { margin: 0 0 6px 18px; padding: 0; }

    /* Stack columns for print to avoid narrow columns and improve density */
    .row > [class*="col-"] { float: none !important; width: 100% !important; display: block !important; }

    /* JANGAN sembunyikan .container, .container-fluid, .row, .col-md-9, section, .feature-card, dsb */

    /* Force colors to black/gray in print for clarity */
    .text-primary, .text-success, .text-info, .text-warning, .text-danger { color: #000 !important; }

    /* Show a simplified table of contents at top of printout */
    .print-toc { display: block; margin-bottom: 8px; font-size: 12px; }
}

/* Hide print-only TOC on screen */
.print-toc { display: none; }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="doc-header">
                <div class="container">
                    <div class="row">
                        <div class="col-12 text-center">
                            <h1><i class="fas fa-network-wired"></i> Sistem Manajemen OLT</h1>
                            <p class="lead">Dokumentasi Lengkap Sistem Manajemen OLT dengan Dukungan Dual SSH/SNMP</p>
                            <div class="mt-3">
                                <span class="badge bg-success">v2.0</span>
                                <span class="badge bg-info">SSH/SNMP Siap</span>
                                <span class="badge bg-warning">Multi-Vendor</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-9 mx-auto">
            <!-- Print-only compact TOC (appears only when printing) -->
            <div class="print-toc">
                <strong>Daftar Isi (Print)</strong>
                <ol>
                    <li>Ikhtisar</li>
                    <li>Fitur Utama</li>
                    <li>Cara Penggunaan</li>
                    <li>Manajemen OLT</li>
                    <li>Monitoring ONU</li>
                    <li>Vendor yang Didukung</li>
                    <li>Pemecahan Masalah</li>
                </ol>
            </div>
            <!-- Overview Section -->
            <section id="overview" class="mb-5">
                <h2><i class="fas fa-info-circle text-primary"></i> Ikhtisar Sistem</h2>
                <div class="feature-card">
                    <p class="lead">Sistem Manajemen OLT adalah platform web yang memungkinkan administrator untuk mengelola perangkat OLT dari berbagai vendor dalam satu interface terpusat. Dengan dukungan 11 vendor dan konektivitas dual (SSH/SNMP), sistem ini menyederhanakan proses monitoring dan konfigurasi jaringan fiber optik.</p>
                    
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <h5><i class="fas fa-rocket text-success"></i> Apa yang Dapat Anda Lakukan?</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Monitor ONU Real-time</strong> - Lihat status, power level, dan konektivitas semua ONU dalam jaringan</li>
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Kelola Multiple OLT</strong> - Satu dashboard untuk semua perangkat OLT dari vendor berbeda</li>
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Konfigurasi Remote</strong> - Enable/disable/reboot ONU tanpa akses fisik ke perangkat</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Backup Data Otomatis</strong> - Semua data ONU tersimpan otomatis untuk analisis</li>
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Interface User-Friendly</strong> - Tampilan modern dengan kode warna untuk memudahkan monitoring</li>
                                        <li><i class="fas fa-check text-success me-2"></i> <strong>Multi-Vendor Support</strong> - Kompatibel dengan 11 vendor populer (HUAWEI, ZTE, FIBERHOME, dll)</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="mb-5">
                <h2><i class="fas fa-star text-primary"></i> Fitur Utama</h2>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="feature-card">
                            <h5><i class="fas fa-network-wired text-success"></i> Kelola OLT dari Berbagai Vendor</h5>
                            <ul>
                                <li><strong>11 Vendor Didukung</strong> - HUAWEI, ZTE, FIBERHOME, NOKIA, VSOL, HSGQ, HIOSO, dll</li>
                                <li><strong>Satu Dashboard</strong> - Kelola semua OLT dari interface tunggal</li>
                                <li><strong>Tambah/Edit OLT</strong> - Konfigurasi mudah dengan form wizard</li>
                                <li><strong>Organisasi Area</strong> - Kelompokkan OLT berdasarkan lokasi</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <h5><i class="fas fa-eye text-info"></i> Monitor ONU Real-time</h5>
                            <ul>
                                <li><strong>Status Live</strong> - Online/Offline dalam satu tampilan</li>
                                <li><strong>Power Level Visual</strong> - Kode warna otomatis (Hijau/Kuning/Merah)</li>
                                <li><strong>Akses Fullscreen</strong> - Modal 95% viewport untuk monitoring nyaman</li>
                                <li><strong>Data Historis</strong> - Backup otomatis untuk analisis</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <h5><i class="fas fa-tools text-warning"></i> Konfigurasi ONU Remote</h5>
                            <ul>
                                <li><strong>Enable/Disable ONU</strong> - Aktifkan atau matikan ONU jarak jauh</li>
                                <li><strong>Reboot ONU</strong> - Restart ONU tanpa akses fisik</li>
                                <li><strong>Multi-method</strong> - Dukungan SSH dan SNMP</li>
                                <li><strong>Perintah Vendor</strong> - Sesuai dengan CLI masing-masing vendor</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <h5><i class="fas fa-exclamation-triangle text-danger"></i> Keterbatasan Sistem</h5>
                            <ul>
                                <li><strong>Tidak Semua OLT Support</strong> - Tergantung kompatibilitas vendor</li>
                                <li><strong>Butuh SSH/SNMP Aktif</strong> - OLT harus mengizinkan akses remote</li>
                                <li><strong>Beberapa Fitur Terbatas</strong> - Sesuai kemampuan masing-masing vendor</li>
                                <li><strong>Koneksi Diperlukan</strong> - Sistem membutuhkan akses jaringan ke OLT</li>
                            </ul>
                        </div>
                    </div>
                </div>
    </section>

    <!-- How to Use Section -->
    <section id="how-to-use" class="mb-5">
                <h2><i class="fas fa-book-open text-primary"></i> Cara Penggunaan</h2>
                
                <div class="feature-card">
                    <h5>Langkah 1: Menambah OLT Baru</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <ol>
                                <li><strong>Klik tombol "Add OLT"</strong> di halaman utama</li>
                                <li><strong>Isi informasi OLT:</strong>
                                    <ul>
                                        <li>IP Address + Port (contoh: 192.168.1.100:22)</li>
                                        <li>Nama OLT yang mudah diingat</li>
                                        <li>Pilih Brand dari 11 vendor yang didukung</li>
                                        <li>Username dan Password untuk akses OLT</li>
                                        <li>Pilih Server dan Area</li>
                                    </ul>
                                </li>
                                <li><strong>Klik "Simpan"</strong> untuk menyimpan konfigurasi</li>
                            </ol>
                        </div>
                        <div class="col-md-4">
                            <div class="alert alert-info">
                                <i class="fas fa-lightbulb"></i> <strong>Tips:</strong><br>
                                Untuk HIOSO EPON 2 Port, gunakan port 80 (HTTP) karena menggunakan web interface
                            </div>
                        </div>
                    </div>
                </div>

                <div class="feature-card">
                    <h5>Langkah 2: Mengakses OLT (Fungsi Open)</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <ol>
                                <li><strong>Klik tombol "Open"</strong> pada OLT yang ingin diakses</li>
                                <li><strong>Modal fullscreen terbuka</strong> otomatis dengan interface OLT</li>
                                <li><strong>Sistem koneksi</strong> secara otomatis via SSH atau SNMP</li>
                                <li><strong>Daftar PON</strong> akan dimuat dalam dropdown</li>
                                <li><strong>Pilih PON</strong> yang ingin dimonitor</li>
                                <li><strong>Data ONU</strong> akan ditampilkan dengan kode warna</li>
                            </ol>
                        </div>
                        <div class="col-md-6">
                            <div class="screenshot-placeholder">
                                <i class="fas fa-desktop fa-3x"></i>
                                <p class="mt-2">Modal Interface OLT<br><small>Fullscreen 95% viewport</small></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="feature-card">
                    <h5>Langkah 3: Monitoring & Konfigurasi ONU</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <i class="fas fa-eye fa-2x text-success mb-2"></i>
                                    <h6>Monitor Status</h6>
                                    <p class="small">Lihat status Online/Offline dengan kode warna otomatis</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-info">
                                <div class="card-body text-center">
                                    <i class="fas fa-signal fa-2x text-info mb-2"></i>
                                    <h6>Cek Power Level</h6>
                                    <p class="small">Monitor kekuatan sinyal dengan indikator visual</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <i class="fas fa-cogs fa-2x text-warning mb-2"></i>
                                    <h6>Konfigurasi ONU</h6>
                                    <p class="small">Enable/Disable/Reboot ONU secara remote</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
    </section>

    <!-- OLT Management Section -->
    <section id="olt-management" class="mb-5">
                <h2><i class="fas fa-server text-primary"></i> Manajemen OLT</h2>
                
                <div class="feature-card">
                    <h5>Operasi CRUD OLT</h5>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6><i class="fas fa-plus"></i> Tambah OLT</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="small">
                                        <li>Konfigurasi IP + Port</li>
                                        <li>Pemilihan brand (11 vendor)</li>
                                        <li>Kredensial SSH/SNMP</li>
                                        <li>Pemilihan metode</li>
                                        <li>Penugasan server & area</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-header bg-warning text-dark">
                                    <h6><i class="fas fa-edit"></i> Edit OLT</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="small">
                                        <li>Update detail koneksi</li>
                                        <li>Ubah metode akses</li>
                                        <li>Modifikasi kredensial</li>
                                        <li>Reassign server/area</li>
                                        <li>Dukungan migrasi brand</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-danger">
                                <div class="card-header bg-danger text-white">
                                    <h6><i class="fas fa-trash"></i> Hapus OLT</h6>
                                </div>
                                <div class="card-body">
                                    <ul class="small">
                                        <li>Dialog konfirmasi</li>
                                        <li>Proteksi cascade delete</li>
                                        <li>Backup data sebelum hapus</li>
                                        <li>Logging audit trail</li>
                                        <li>Opsi soft delete</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="feature-card">
                    <h5><i class="fas fa-info-circle text-info"></i> Tips Penggunaan Manajemen OLT</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-lightbulb text-warning"></i> Rekomendasi Setup</h6>
                            <ul>
                                <li><strong>Gunakan nama OLT yang deskriptif</strong> - Mudah diingat dan identifikasi</li>
                                <li><strong>Kelompokkan berdasarkan area</strong> - Organisasi yang lebih baik</li>
                                <li><strong>Test koneksi setelah menambah</strong> - Pastikan parameter benar</li>
                                <li><strong>Update kredensial jika berubah</strong> - Hindari kegagalan akses</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-exclamation-triangle text-danger"></i> Hal yang Perlu Diperhatikan</h6>
                            <ul>
                                <li><strong>Tidak semua OLT mendukung SSH/SNMP</strong> - Cek kompatibilitas dulu</li>
                                <li><strong>Beberapa vendor perlu konfigurasi khusus</strong> - Lihat dokumentasi vendor</li>
                                <li><strong>Koneksi jaringan harus stabil</strong> - Untuk monitoring real-time</li>
                                <li><strong>Kredensial harus benar</strong> - Username/password sesuai OLT</li>
                            </ul>
                        </div>
                    </div>
                </div>
    </section>

    <!-- ONU Monitoring Section -->
    <section id="onu-monitoring" class="mb-5">
                <h2><i class="fas fa-chart-line text-primary"></i> Monitoring ONU</h2>
                
                <div class="feature-card">
                    <h5>Monitoring ONU Real-time</h5>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <h6>Kode Warna Power Level</h6>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Range Power</th>
                                        <th>Status</th>
                                        <th>Warna</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>>= -25 dBm</td>
                                        <td><span class="status-badge power-good">Baik</span></td>
                                        <td><i class="fas fa-circle power-good"></i> Hijau</td>
                                        <td>Kekuatan sinyal optimal</td>
                                    </tr>
                                    <tr>
                                        <td>-25 to -30 dBm</td>
                                        <td><span class="status-badge power-warning">Peringatan</span></td>
                                        <td><i class="fas fa-circle power-warning"></i> Kuning</td>
                                        <td>Dapat diterima tapi monitor</td>
                                    </tr>
                                    <tr>
                                        <td>&lt; -30 dBm</td>
                                        <td><span class="status-badge power-danger">Bahaya</span></td>
                                        <td><i class="fas fa-circle power-danger"></i> Merah</td>
                                        <td>Sinyal buruk, perlu perhatian</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <h6>Indikator Status</h6>
                            <div class="mb-2">
                                <span class="status-badge status-online">Online</span> - ONU aktif
                            </div>
                            <div class="mb-2">
                                <span class="status-badge status-offline">Offline</span> - ONU terputus
                            </div>
                            <div class="mb-2">
                                <span class="status-badge status-warning">Unknown</span> - Status tidak jelas
                            </div>
                        </div>
                    </div>
                </div>

                <div class="feature-card">
                    <h5>Aksi Konfigurasi ONU</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <i class="fas fa-power-off fa-2x text-success mb-2"></i>
                                    <h6>Enable ONU</h6>
                                    <p class="small">Aktifkan ONU yang terputus</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <i class="fas fa-pause fa-2x text-warning mb-2"></i>
                                    <h6>Disable ONU</h6>
                                    <p class="small">Putuskan ONU sementara</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <i class="fas fa-redo fa-2x text-danger mb-2"></i>
                                    <h6>Reboot ONU</h6>
                                    <p class="small">Restart ONU secara remote</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
    </section>

    <!-- Supported Vendors Section -->
    <section id="vendors" class="mb-5">
                <h2><i class="fas fa-industry text-primary"></i> Vendor yang Didukung</h2>
                
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle"></i> 
                    <strong>Penting:</strong> Tidak semua perangkat OLT dari vendor-vendor ini dijamin kompatibel 100%. 
                    Kompatibilitas tergantung pada firmware, versi OS, dan konfigurasi masing-masing perangkat.
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="feature-card">
                            <h5><i class="fas fa-check-circle text-success"></i> 11 Vendor Terintegrasi</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Vendor GPON:</h6>
                                    <ul>
                                        <li><span class="badge bg-success">HUAWEI GPON</span> - Support penuh</li>
                                        <li><span class="badge bg-success">ZTE GPON</span> - Support penuh</li>
                                        <li><span class="badge bg-success">FIBERHOME GPON</span> - Support baik</li>
                                        <li><span class="badge bg-warning">NOKIA GPON</span> - Support terbatas</li>
                                        <li><span class="badge bg-success">VSOL GPON</span> - Support baik</li>
                                        <li><span class="badge bg-info">GPON LAIN</span> - Fallback mode</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Vendor EPON:</h6>
                                    <ul>
                                        <li><span class="badge bg-success">VSOL EPON</span> - Support penuh</li>
                                        <li><span class="badge bg-success">HSGQ EPON</span> - Support baik</li>
                                        <li><span class="badge bg-primary">HIOSO EPON</span> - Web interface</li>
                                        <li><span class="badge bg-warning">HSGQ GPON</span> - Support terbatas</li>
                                        <li><span class="badge bg-info">EPON LAIN</span> - Fallback mode</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="feature-card">
                            <h5><i class="fas fa-info-circle text-info"></i> Level Support</h5>
                            <div class="mb-3">
                                <span class="badge bg-success">Support Penuh</span>
                                <p class="small mt-1">Semua fitur tersedia (PON list, ONU monitoring, konfigurasi)</p>
                            </div>
                            <div class="mb-3">
                                <span class="badge bg-warning">Support Terbatas</span>
                                <p class="small mt-1">Monitoring basic, beberapa fitur mungkin tidak tersedia</p>
                            </div>
                            <div class="mb-3">
                                <span class="badge bg-primary">Web Interface</span>
                                <p class="small mt-1">Akses via web browser (port 80/443)</p>
                            </div>
                            <div class="mb-3">
                                <span class="badge bg-info">Fallback Mode</span>
                                <p class="small mt-1">Data simulasi untuk testing/demo</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="feature-card">
                    <h5><i class="fas fa-exclamation-circle text-danger"></i> OLT yang Tidak Didukung</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Kemungkinan Masalah:</h6>
                            <ul>
                                <li><strong>OLT lama</strong> - Firmware versi lama tanpa SSH/SNMP</li>
                                <li><strong>Konfigurasi khusus</strong> - CLI command berbeda dari standar</li>
                                <li><strong>Akses terbatas</strong> - SSH/SNMP dinonaktifkan admin</li>
                                <li><strong>Vendor tidak umum</strong> - Tidak ada dalam daftar 11 vendor</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Solusi Alternatif:</h6>
                            <ul>
                                <li><strong>Gunakan "GPON LAIN" atau "EPON LAIN"</strong> - Mode fallback</li>
                                <li><strong>Cek manual via SSH/Telnet</strong> - Test kompatibilitas command</li>
                                <li><strong>Update firmware OLT</strong> - Jika memungkinkan</li>
                                <li><strong>Hubungi support</strong> - Untuk penambahan vendor baru</li>
                            </ul>
                        </div>
                    </div>
                </div>
    </section>

    <!-- Troubleshooting Section -->
    <section id="troubleshooting" class="mb-5">
                <h2><i class="fas fa-tools text-primary"></i> Pemecahan Masalah</h2>
                
                <div class="feature-card">
                    <h5>Masalah Umum & Solusi</h5>
                    
                    <div class="accordion" id="troubleshootingAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Koneksi SSH Gagal
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" data-bs-parent="#troubleshootingAccordion">
                                <div class="accordion-body">
                                    <strong>Gejala:</strong> "Ekstensi SSH2 tidak tersedia" atau "Tidak dapat terhubung ke OLT via SSH"<br>
                                    <strong>Solusi:</strong>
                                    <ul>
                                        <li>Install ekstensi PHP SSH2: <code>apt-get install php-ssh2</code></li>
                                        <li>Verifikasi akses port SSH: <code>telnet {OLT_IP} 22</code></li>
                                        <li>Periksa aturan firewall</li>
                                        <li>Verifikasi kredensial SSH OLT</li>
                                        <li>Coba metode SNMP sebagai fallback</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                    <i class="fas fa-chart-line text-info me-2"></i>
                                    Masalah Koneksi SNMP
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                <div class="accordion-body">
                                    <strong>Gejala:</strong> "Ekstensi SNMP tidak tersedia" atau "Tidak dapat terhubung ke OLT via SNMP"<br>
                                    <strong>Solusi:</strong>
                                    <ul>
                                        <li>Install ekstensi PHP SNMP: <code>apt-get install php-snmp</code></li>
                                        <li>Verifikasi community string SNMP</li>
                                        <li>Periksa versi SNMP (v1/v2c/v3)</li>
                                        <li>Test SNMP walk: <code>snmpwalk -v2c -c public {OLT_IP} 1.3.6.1.2.1.1.1.0</code></li>
                                        <li>Verifikasi konfigurasi SNMP OLT</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                    <i class="fas fa-database text-danger me-2"></i>
                                    Tidak Ada Data ONU Ditemukan
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                <div class="accordion-body">
                                    <strong>Gejala:</strong> PON list termuat tapi tidak ada data ONU yang muncul<br>
                                    <strong>Solusi:</strong>
                                    <ul>
                                        <li>Periksa mapping perintah vendor di vendor_commands.php</li>
                                        <li>Verifikasi port PON memiliki ONU aktif</li>
                                        <li>Test perintah vendor secara manual via SSH</li>
                                        <li>Periksa fungsi parser untuk vendor</li>
                                        <li>Aktifkan mode debug untuk melihat output mentah</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFour">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                    <i class="fas fa-file-alt text-success me-2"></i>
                                    File Tidak Tersimpan
                                </button>
                            </h2>
                            <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#troubleshootingAccordion">
                                <div class="accordion-body">
                                    <strong>Gejala:</strong> Data ONU tampil tapi file tidak dibuat<br>
                                    <strong>Solusi:</strong>
                                    <ul>
                                        <li>Periksa permission direktori: <code>chmod 755 getdata/debug/</code></li>
                                        <li>Verifikasi akses write web server</li>
                                        <li>Periksa ketersediaan disk space</li>
                                        <li>Review error logs: <code>tail -f /var/log/apache2/error.log</code></li>
                                        <li>Pastikan fungsi saveOnuListToFile dipanggil</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>



            <!-- Footer -->
            <section class="mb-5">
                <div class="feature-card text-center">
                    <h5><i class="fas fa-check-circle text-success"></i> Dokumentasi Lengkap</h5>
                    <p>Dokumentasi ini mencakup semua aspek dari Sistem Manajemen OLT. Untuk pertanyaan lebih lanjut atau dukungan, hubungi tim development.</p>
                    <div class="mt-3">
                   
                        <a href="olt_documentation_pdf.php" class="btn btn-success no-print-pdf" target="_blank">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
// Smooth scrolling for navigation
document.querySelectorAll('#docs-nav a').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        target.scrollIntoView({ behavior: 'smooth' });
        
        // Update active nav
        document.querySelectorAll('#docs-nav a').forEach(a => a.classList.remove('active'));
        this.classList.add('active');
    });
});

// Update active nav on scroll
window.addEventListener('scroll', function() {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('#docs-nav a');
    
    let current = '';
    sections.forEach(section => {
        const sectionTop = section.offsetTop - 100;
        if (window.pageYOffset >= sectionTop) {
            current = section.getAttribute('id');
        }
    });
    
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === '#' + current) {
            link.classList.add('active');
        }
    });
});
</script>

<?php require 'footer.php'; ?>