<?php
/**
 * TEST FILE - Untuk test output HTML vs CLI tanpa perlu database
 * 
 * Akses via Browser: http://domain/path/TEST_REPORT_OUTPUT.php
 * Akses via CLI: php TEST_REPORT_OUTPUT.php
 */

// Deteksi output CLI vs Browser
$isCliOutput = (PHP_SAPI === 'cli');

if (!$isCliOutput) {
    // ============================================================
    // BROWSER OUTPUT - PURE HTML + BOOTSTRAP (100% HTML)
    // ============================================================
    echo "<!DOCTYPE html>\n";
    echo "<html lang='id'>\n";
    echo "<head>\n";
    echo "    <meta charset='UTF-8'>\n";
    echo "    <meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    echo "    <title>CEK TAGIHAN HARIAN - REPORT ONLY</title>\n";
    echo "    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>\n";
    echo "    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css' rel='stylesheet'>\n";
    echo "    <style>\n";
    echo "        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 30px 0; }\n";
    echo "        .main-container { background: white; border-radius: 15px; box-shadow: 0 15px 50px rgba(0,0,0,0.15); overflow: hidden; margin-bottom: 30px; }\n";
    echo "        .header-section { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 30px; text-align: center; }\n";
    echo "        .header-section h1 { font-weight: 800; margin: 0; font-size: 32px; }\n";
    echo "        .header-section .subtitle { margin: 12px 0 0 0; font-size: 15px; opacity: 0.95; }\n";
    echo "        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; padding: 30px; background: #f8f9fa; border-bottom: 1px solid #e9ecef; }\n";
    echo "        .info-item { background: white; padding: 18px; border-radius: 10px; border-left: 5px solid #667eea; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }\n";
    echo "        .info-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #6c757d; margin-bottom: 10px; }\n";
    echo "        .info-value { font-size: 18px; font-weight: 800; color: #2d3748; }\n";
    echo "        .content-section { padding: 35px; }\n";
    echo "        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }\n";
    echo "        .stat-box { background: #f5f7fa; padding: 25px; border-radius: 10px; border: 1px solid #e9ecef; text-align: center; }\n";
    echo "        .stat-number { font-size: 36px; font-weight: 800; margin-bottom: 8px; }\n";
    echo "        .stat-label { font-size: 12px; color: #6c757d; font-weight: 600; text-transform: uppercase; }\n";
    echo "        .footer-section { background: #f8f9fa; padding: 25px 30px; border-top: 2px solid #e9ecef; text-align: center; }\n";
    echo "    </style>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "    <div class='container'>\n";
    
    // Header
    echo "        <div class='main-container'>\n";
    echo "            <div class='header-section'>\n";
    echo "                <h1><i class='fas fa-file-invoice-dollar'></i> CEK TAGIHAN HARIAN</h1>\n";
    echo "                <p class='subtitle'>Monitoring Pembayaran - Report Only Mode</p>\n";
    echo "            </div>\n";
    
    // Info Grid
    echo "            <div class='info-grid'>\n";
    echo "                <div class='info-item'>\n";
    echo "                    <div class='info-label'><i class='fas fa-user'></i> Pemilik</div>\n";
    echo "                    <div class='info-value'>FIBERQ</div>\n";
    echo "                </div>\n";
    echo "                <div class='info-item'>\n";
    echo "                    <div class='info-label'><i class='fas fa-calendar'></i> Tanggal</div>\n";
    echo "                    <div class='info-value'>" . date('Y-m-d') . "</div>\n";
    echo "                </div>\n";
    echo "                <div class='info-item'>\n";
    echo "                    <div class='info-label'><i class='fas fa-clock'></i> Waktu</div>\n";
    echo "                    <div class='info-value'>" . date('H:i:s') . "</div>\n";
    echo "                </div>\n";
    echo "                <div class='info-item'>\n";
    echo "                    <div class='info-label'><i class='fas fa-period'></i> Periode</div>\n";
    echo "                    <div class='info-value'>April 2026</div>\n";
    echo "                </div>\n";
    echo "            </div>\n";
    
    // Statistics
    echo "            <div class='content-section'>\n";
    echo "                <h5 style='margin-bottom: 20px;'><i class='fas fa-chart-bar'></i> STATISTIK</h5>\n";
    echo "                <div class='stat-grid'>\n";
    echo "                    <div class='stat-box'>\n";
    echo "                        <div class='stat-number' style='color: #667eea;'>50</div>\n";
    echo "                        <div class='stat-label'>Total Pelanggan</div>\n";
    echo "                    </div>\n";
    echo "                    <div class='stat-box'>\n";
    echo "                        <div class='stat-number' style='color: #51cf66;'>40</div>\n";
    echo "                        <div class='stat-label'>Sudah Bayar</div>\n";
    echo "                    </div>\n";
    echo "                    <div class='stat-box'>\n";
    echo "                        <div class='stat-number' style='color: #ff6b6b;'>10</div>\n";
    echo "                        <div class='stat-label'>Belum Bayar</div>\n";
    echo "                    </div>\n";
    echo "                    <div class='stat-box'>\n";
    echo "                        <div class='stat-number' style='color: #ffa94d;'>✓</div>\n";
    echo "                        <div class='stat-label'>Mode: REPORT</div>\n";
    echo "                    </div>\n";
    echo "                </div>\n";
    echo "            </div>\n";
    
    echo "            <div class='footer-section'>\n";
    echo "                <p><strong>CEK TAGIHAN HARIAN - REPORT ONLY</strong></p>\n";
    echo "                <p style='margin: 10px 0;'>Mode: MONITORING ONLY | " . date('Y-m-d H:i:s') . "</p>\n";
    echo "            </div>\n";
    echo "        </div>\n";
    echo "    </div>\n";
    echo "    <script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>\n";
    echo "</body>\n";
    echo "</html>\n";
    
} else {
    // ============================================================
    // CLI OUTPUT - PURE TEXT + ASCII BOX (100% TEXT)
    // ============================================================
    echo "\n╔════════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                    CEK TAGIHAN HARIAN - REPORT ONLY (MONITORING)                ║\n";
    echo "║                  (Tanpa Aksi Matikan/Hidupkan di Mikrotik)                      ║\n";
    echo "╠════════════════════════════════════════════════════════════════════════════════╣\n";
    echo "║ Waktu Mulai             : " . str_pad(date('Y-m-d H:i:s'), 63) . "║\n";
    echo "║ Pemilik                 : " . str_pad('FIBERQ', 63) . "║\n";
    echo "║ Mode                    : " . str_pad('REPORT ONLY (Tanpa Aksi Mikrotik)', 63) . "║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════════╝\n";

    echo "\n╔════════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║ STATISTIK PELANGGAN\n";
    echo "╠════════════════════════════════════════════════════════════════════════════════╣\n";
    printf("║ %-30s : %3d pelanggan %34s║\n", "Total Pelanggan Diperiksa", 50, "");
    printf("║ %-30s : %3d pelanggan %34s║\n", "Status: SUDAH BAYAR", 40, "");
    printf("║ %-30s : %3d pelanggan %34s║\n", "Status: BELUM BAYAR", 10, "");
    echo "╚════════════════════════════════════════════════════════════════════════════════╝\n";

    echo "\n═══════════════════════════════════════════════════════════════════════════════════\n";
    echo "                   ✓ SELESAI - PROSES CEK TAGIHAN HARIAN (REPORT ONLY)\n";
    echo "                         Selesai pada: " . date('Y-m-d H:i:s') . "\n";
    echo "═══════════════════════════════════════════════════════════════════════════════════\n";
}
