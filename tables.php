<?php
// Buffer semua output dari sini supaya header('Location: ...') masih bisa
// dipanggil nanti (lihat blok PRG redirect di bawah) walau HTML sudah
// "dicetak" sejauh ini - belum benar-benar terkirim ke browser selama
// buffer ini belum di-flush/dibuang.
ob_start();
// Card "Cron Tiket Maintenance Otomatis" (+ AJAX handler & vars-nya) DIPINDAHKAN
// ke system_setting.php atas permintaan user -- lihat memory
// project_cron_ticket_cards_moved_to_system_setting.
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Customer_PPPOE', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer PPPOE.</div></div>';
        require 'footer.php';
        exit;
    }
}


// --- Auto-index kolom yang sering di-filter/sort di halaman ini (IDPEL, NAMA, NOWA, PAKET,
//     AREA, PEMILIK, ODP). Ditandai dengan file penanda supaya ALTER TABLE cuma dicoba SEKALI
//     (bukan tiap request) -- halaman ini termasuk yang paling sering diakses.
$pelangganIndexFlag = __DIR__ . '/notifbot/data/.pelanggan_indexed';
if (isset($conn) && $conn && !file_exists($pelangganIndexFlag)) {
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_idpel` (`IDPEL`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_nama` (`NAMA`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_nowa` (`NOWA`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_paket` (`PAKET`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_area` (`AREA`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_pemilik` (`PEMILIK`)");
    @mysqli_query($conn, "ALTER TABLE `pelanggan` ADD INDEX `idx_pelanggan_odp` (`ODP`)");
    // Kombinasi AREA+PEMILIK dipakai berkali-kali per baris untuk lookup ke tabel `server`
    @mysqli_query($conn, "ALTER TABLE `server` ADD INDEX `idx_server_area_pemilik` (`AREA`, `PEMILIK`)");
    @file_put_contents($pelangganIndexFlag, date('Y-m-d H:i:s'));
}
?>

<!-- Loading screen dinonaktifkan sesuai permintaan -->

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
        background:
            radial-gradient(circle at 15% 20%, rgba(var(--bs-primary-rgb, 37, 99, 235), 0.32) 0%, transparent 45%),
            radial-gradient(circle at 85% 80%, rgba(59, 130, 246, 0.22) 0%, transparent 45%),
            linear-gradient(135deg, var(--bs-body-bg, #0b1220) 0%, #0f172a 55%, #111827 100%);
        background-size: 200% 200%, 200% 200%, 100% 100%;
        animation: gradientShift 8s ease infinite;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 1;
        transition: opacity 0.8s ease-out, visibility 0.8s ease-out;
    }

    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    #loading.fade-out {
        opacity: 0;
        visibility: hidden;
    }

    .loading-content {
        text-align: center;
        color: var(--bs-body-color, #f8fafc);
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    .spinner {
        width: 60px;
        height: 60px;
        border: 4px solid rgba(var(--bs-primary-rgb, 37, 99, 235), 0.25);
        border-top-color: var(--logo-secondary, #3b82f6);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
        box-shadow: 0 0 20px rgba(var(--bs-primary-rgb, 37, 99, 235), 0.3);
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.45);
        animation: pulse 2s ease-in-out infinite;
        letter-spacing: 0.5px;
    }

    @keyframes pulse {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.9;
            transform: scale(1.02);
        }
    }

    .loading-progress {
        width: 200px;
        height: 4px;
        background: rgba(var(--bs-primary-rgb, 37, 99, 235), 0.2);
        border-radius: 2px;
        margin: 0 auto;
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.25);
    }

    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, var(--logo-primary, #2563eb), var(--logo-secondary, #3b82f6));
        border-radius: 2px;
        width: 0%;
        animation: progress 2s ease-out infinite;
        box-shadow: 0 0 10px rgba(var(--bs-primary-rgb, 37, 99, 235), 0.45);
    }

    @keyframes progress {
        0% { width: 0%; }
        50% { width: 70%; }
        100% { width: 100%; }
    }

    .modal .modal-header .btn-close:hover,
    .modal .modal-header .btn-close:focus {
        background-color: #ffffff;
        border-color: #ffffff;
        transform: scale(1.04);
    }

    .modal .modal-header.bg-primary .btn-close,
    .modal .modal-header.bg-dark .btn-close,
    .modal .modal-header.text-white .btn-close {
        filter: none !important;
        border-color: rgba(255, 255, 255, 0.95);
        background-color: rgba(255, 255, 255, 0.98);
    }

    .modal .modal-header.bg-primary .btn-close:hover,
    .modal .modal-header.bg-dark .btn-close:hover,
    .modal .modal-header.text-white .btn-close:hover,
    .modal .modal-header.bg-primary .btn-close:focus,
    .modal .modal-header.bg-dark .btn-close:focus,
    .modal .modal-header.text-white .btn-close:focus {
        background-color: #ffffff;
        border-color: #ffffff;
    }

    .modal .modal-header .btn-close.btn-close-white {
        filter: none !important;
    }

    .modal-backdrop {
        width: 100vw;
        height: 100vh;
    }

    /* Card untuk mobile view tabel */
    .user-card {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        background: #fff;
    }
    .user-card h6 {
        margin-bottom: 10px;
        color: #333;
    }
    .user-card p {
        margin: 5px 0;
        font-size: 14px;
    }
    .user-card .actions {
        margin-top: 10px;
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        z-index: 1100;
        position: relative;
    }
    .user-card .actions button,
    .user-card .actions a {
        pointer-events: auto;
        z-index: 1101;
        position: relative;
    }

    .status-action-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 4px;
        margin-bottom: 4px;
    }

    .status-action-row > div[id^="remoteContainer-"] {
        display: inline-flex;
    }

    .customer-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        min-height: 0;
        padding: 0.2rem 0.5rem;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.2;
        text-align: center;
        white-space: nowrap;
        border-radius: 999px;
    }

    #dataTable thead th {
        background: linear-gradient(135deg, #f68013, #e26f00);
        color: #ffffff !important;
        opacity: 1 !important;
        border-color: rgba(255, 255, 255, 0.18) !important;
        letter-spacing: 0.4px;
    }

    #dataTable .row-number-cell {
        font-weight: 700;
        color: #ffffff;
        background: rgba(246, 128, 19, 0.9);
        border-radius: 999px;
        min-width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 6px;
        line-height: 1;
    }

    .row-no-icon-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        cursor: pointer;
        height: 100%;
    }

    .row-no-icon-wrap .avatar {
        width: auto !important;
        height: auto !important;
        flex: 1 1 0;
        max-height: 60px;
        aspect-ratio: 1 / 1;
        border-radius: 50%;
    }

    .modal-action-btn {
        --bs-btn-color: var(--white, #ffffff);
        --bs-btn-hover-color: var(--white, #ffffff);
        --bs-btn-active-color: var(--white, #ffffff);
        --bs-btn-disabled-color: var(--white, #ffffff);
        color: var(--white, #ffffff) !important;
        text-decoration: none !important;
        font-weight: 700 !important;
        opacity: 1 !important;
        text-shadow: none !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
        background-clip: border-box !important;
        -webkit-background-clip: border-box !important;
    }

    .modal-action-btn:hover,
    .modal-action-btn:focus,
    .modal-action-btn:active {
        color: var(--white, #ffffff) !important;
        text-decoration: none !important;
        opacity: 1 !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
    }

    .modal-action-btn,
    div[id^="remoteContainerModal-"] .modal-action-btn {
        width: 100% !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .modal-action-btn *,
    div[id^="remoteContainerModal-"] .modal-action-btn * {
        color: var(--white, #ffffff) !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
        opacity: 1 !important;
        text-shadow: none !important;
    }

    .modal-action-btn.btn-secondary {
        background-color: var(--logo-secondary, #3b82f6) !important;
        border-color: var(--logo-secondary, #3b82f6) !important;
    }

    .modal-action-btn.btn-secondary:hover,
    .modal-action-btn.btn-secondary:focus,
    .modal-action-btn.btn-secondary:active {
        background-color: var(--logo-primary, #2563eb) !important;
        border-color: var(--logo-primary, #2563eb) !important;
    }

    .customer-transaction-frame {
        width: 100%;
        min-height: 320px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
    }

    .overview-modal-dialog {
        width: 100vw;
        max-width: 100vw;
        height: 100vh;
        margin: 0;
    }

    .modal[id^="exampleoverview"] {
        --bs-modal-width: 100vw;
        padding: 0 !important;
    }

    .modal[id^="exampleoverview"] .overview-modal-dialog,
    .modal[id^="exampleoverview"] .modal-dialog {
        width: 100vw !important;
        max-width: 100vw !important;
        min-width: 100vw !important;
        height: 100vh !important;
        margin: 0 !important;
    }

    .modal[id^="exampleoverview"] .overview-modal-content,
    .modal[id^="exampleoverview"] .modal-content {
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100vh !important;
        max-height: 100vh !important;
        border-radius: 0 !important;
    }

    .overview-modal-content {
        flex-direction: column !important;
        display: flex !important;
        width: 100%;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
    }

    .overview-modal-body {
        font-size: 13.5px;
        padding: 16px;
        flex: 1 1 auto;
        overflow-y: auto;
    }

    .modal[id^="exampleoverview"] .overview-main-row {
        align-items: flex-start;
    }

    .modal[id^="exampleoverview"] .overview-formdata-col .form-label {
        font-size: 13px;
        margin-bottom: 0.25rem;
        color: #334155;
        font-weight: 700;
    }

    .modal[id^="exampleoverview"] .overview-formdata-col .form-control {
        font-size: 14px;
        padding: 0.45rem 0.65rem;
    }

    .modal[id^="exampleoverview"] .overview-formdata-col .mb-1 {
        margin-bottom: 0.75rem !important;
    }

    body.app-theme-dark .modal[id^="exampleoverview"] .overview-formdata-col .form-label {
        color: #cbd5e1 !important;
    }

    .modal[id^="exampleoverview"] .overview-profile-col .mb-0 {
        margin-bottom: 0.4rem !important;
    }

    .modal[id^="exampleoverview"] .overview-profile-col .form-label {
        font-size: 11px;
        margin-bottom: 0.2rem;
        color: #334155;
        font-weight: 700;
    }

    .modal[id^="exampleoverview"] .overview-health-col .overview-created {
        display: block;
        margin-bottom: 0.35rem;
    }

    .modal[id^="exampleoverview"] .overview-health-col .overview-meta-item {
        margin-bottom: 0.25rem !important;
        line-height: 1.35;
    }

    .modal[id^="exampleoverview"] .overview-health-stack {
        margin-top: 0.45rem;
    }

    .sla-history-card {
        background: #eef4ff;
        border: 1px solid #cbdcf7;
        border-radius: 10px;
        padding: 8px 10px;
        margin-bottom: 4px;
    }

    .sla-history-title {
        font-weight: 700;
        font-size: 12px;
        color: #1e3a8a;
        margin-bottom: 6px;
    }

    .sla-history-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        max-height: 150px;
        display: block;
        overflow-y: auto;
    }

    .sla-history-table thead {
        display: table;
        width: 100%;
        table-layout: fixed;
    }

    .sla-history-table tbody {
        display: block;
        max-height: 120px;
        overflow-y: auto;
    }

    .sla-history-table tbody tr {
        display: table;
        width: 100%;
        table-layout: fixed;
    }

    .sla-history-table th {
        background: linear-gradient(135deg, #f68013, #e26f00);
        color: #fff;
        text-align: left;
        padding: 5px 8px;
        font-size: 11px;
        text-transform: uppercase;
    }

    .sla-history-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #e2e8f0;
        color: #334155;
    }

    body.app-theme-dark .sla-history-card {
        background: #1e293b !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .sla-history-title {
        color: #93c5fd !important;
    }
    body.app-theme-dark .sla-history-table td {
        color: #e2e8f0 !important;
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }

    .modal[id^="exampleoverview"] [id^="data-status-"] {
        font-size: 13px !important;
        line-height: 1.25;
    }

    .modal[id^="exampleoverview"] [id^="data-paket-aktif-modal-"],
    .modal[id^="exampleoverview"] [id^="data-sla-modal-"] {
        display: block;
        line-height: 1.25;
    }

    .modal[id^="exampleoverview"] [id^="data-info-"] {
        display: block;
        line-height: 1.4;
        background: #f8fafc;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 8px 10px;
    }

    .modal[id^="exampleoverview"] .overview-health-stack .btn,
    .modal[id^="exampleoverview"] .overview-health-stack .send-invoice,
    .modal[id^="exampleoverview"] .overview-health-stack .disable-customer,
    .modal[id^="exampleoverview"] .overview-health-stack .active-customer {
        border-radius: 8px;
        font-weight: 700;
    }

    .modal[id^="exampleoverview"] .modal-header .overview-close-btn {
        opacity: 1 !important;
        filter: none !important;
        background-color: #ffffff !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 999px;
        box-shadow: 0 1px 4px rgba(15, 23, 42, 0.2);
    }

    .modal[id^="exampleoverview"] .modal-header .overview-close-btn:hover,
    .modal[id^="exampleoverview"] .modal-header .overview-close-btn:focus {
        background-color: #f8fafc !important;
        border-color: #94a3b8 !important;
        box-shadow: 0 0 0 0.15rem rgba(59, 130, 246, 0.2);
    }

    body.app-theme-dark .modal[id^="exampleoverview"] .modal-header .overview-close-btn {
        background-color: #f8fafc !important;
        border-color: #e2e8f0 !important;
    }

    body.app-theme-dark .modal[id^="exampleoverview"] .overview-profile-col .form-label {
        color: #cbd5e1;
    }

    body.app-theme-dark .modal[id^="exampleoverview"] [id^="data-info-"] {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0 !important;
    }

    [id^="data-status2-"],
    [id^="data-status-"],
    [id^="data-paket-aktif-"],
    [id^="data-paket-aktif-modal-"],
    [id^="data-sla-"],
    [id^="data-sla-modal-"],
    [id^="data-realtime-"],
    [id^="data-info-"] {
        color: #1e293b !important;
        font-weight: 700;
        font-size: 11px !important;
        line-height: 1.6;
    }

    body.app-theme-dark [id^="data-status2-"],
    body.app-theme-dark [id^="data-status-"],
    body.app-theme-dark [id^="data-paket-aktif-"],
    body.app-theme-dark [id^="data-paket-aktif-modal-"],
    body.app-theme-dark [id^="data-sla-"],
    body.app-theme-dark [id^="data-sla-modal-"],
    body.app-theme-dark [id^="data-realtime-"],
    body.app-theme-dark [id^="data-info-"] {
        color: #e2e8f0 !important;
    }

    [id^="data-status2-"] .badge,
    [id^="data-status-"] .badge,
    [id^="data-paket-aktif-"] .badge,
    [id^="data-paket-aktif-modal-"] .badge,
    [id^="data-sla-"] .badge,
    [id^="data-sla-modal-"] .badge,
    [id^="data-realtime-"] .badge,
    [id^="data-info-"] .badge {
        color: #ffffff !important;
        text-shadow: none !important;
        border: 1px solid rgba(15, 23, 42, 0.22);
        opacity: 1 !important;
        font-size: 10px !important;
    }

    /* Baris atas: badge status/paket/SLA disatukan dalam satu baris agar tidak tinggi */
    .status-top-badges {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        align-items: center;
        gap: 4px;
        margin-bottom: 4px;
    }

    .status-top-badges > * {
        margin: 0 !important;
        display: inline-flex !important;
    }

    /* Baris detail status (kuota, uptime, link up/down, dll) - grid 2 kolom, ringkas & rapi */
    [id^="data-realtime-"],
    [id^="data-info-"] {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        column-gap: 10px;
        row-gap: 0;
    }

    .status-detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 6px;
        font-size: 10.5px !important;
        font-weight: 700;
        line-height: 1.5;
        text-align: left;
    }

    .status-detail-label {
        color: #8898aa !important;
        font-weight: 500;
        white-space: nowrap;
    }

    .status-detail-value {
        text-align: right;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100px;
    }

    body.app-theme-dark .status-detail-label {
        color: #94a3b8 !important;
    }

    [id^="data-status2-"] .badge.bg-secondary,
    [id^="data-status-"] .badge.bg-secondary,
    [id^="data-paket-aktif-"] .badge.bg-secondary,
    [id^="data-paket-aktif-modal-"] .badge.bg-secondary,
    [id^="data-sla-"] .badge.bg-secondary,
    [id^="data-sla-modal-"] .badge.bg-secondary,
    [id^="data-realtime-"] .badge.bg-secondary,
    [id^="data-info-"] .badge.bg-secondary {
        background-color: #334155 !important;
        border-color: #1e293b !important;
        color: #f8fafc !important;
    }

    [id^="data-sla-"] .badge.bg-gradient-info,
    [id^="data-sla-modal-"] .badge.bg-gradient-info {
        background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%) !important;
        border-color: #075985 !important;
    }

    .sla-history-card {
        border: 1px solid #dbeafe;
    }

    .sla-history-card .card-header {
        background: #eff6ff;
        color: #1e3a8a;
        border-bottom: 1px solid #dbeafe;
    }

    body.app-theme-dark .sla-history-card {
        background-color: #0f172a;
        border-color: #334155;
    }

    body.app-theme-dark .sla-history-card .card-header {
        background: #1e293b;
        color: #e2e8f0;
        border-bottom-color: #334155;
    }

    @media (max-width: 991.98px) {
        .overview-modal-dialog {
            width: 100vw;
            max-width: 100vw;
            height: 100vh;
            margin: 0;
        }

        .modal[id^="exampleoverview"] .overview-modal-dialog,
        .modal[id^="exampleoverview"] .modal-dialog,
        .modal[id^="exampleoverview"] .overview-modal-content,
        .modal[id^="exampleoverview"] .modal-content {
            width: 100vw !important;
            max-width: 100vw !important;
            height: 100vh !important;
            max-height: 100vh !important;
            margin: 0 !important;
        }

        .overview-modal-body {
            font-size: 13px;
            padding: 12px;
        }
    }

    .acs-device-card {
        background: #fff;
        color: #27272a;
        border-color: #d4d4d8;
    }

    .acs-device-card,
    .acs-device-card * {
        color: #27272a;
    }

    .acs-device-info-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 6px;
    }

    .acs-device-info-item {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        min-width: 0;
    }

    .acs-device-info-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #f68013;
        margin-bottom: 3px;
    }

    .acs-device-info-value {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-ssid-section {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-ssid-title {
        font-weight: 700;
        margin-bottom: 8px;
        color: #27272a;
    }

    .acs-ssid-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .acs-ssid-row {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        min-width: 0;
    }

    .acs-ssid-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
    }

    .acs-ssid-name {
        font-size: 12px;
        font-weight: 700;
        color: #f68013;
    }

    .acs-ssid-value {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-device-action-row {
        margin-top: 10px;
        display: flex;
        justify-content: center;
    }

    .acs-param-key {
        color: #f68013 !important;
        font-weight: 700;
    }

    .acs-param-value {
        color: #27272a !important;
        font-weight: 600;
    }

    .acs-empty-text {
        color: #71717a !important;
    }

    .acs-wan-section {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-wan-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .acs-wan-title {
        font-weight: 700;
        color: #27272a;
    }

    .acs-wan-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .acs-wan-card {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 10px 12px;
    }

    .acs-wan-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .acs-wan-card-title {
        font-weight: 700;
        color: #27272a;
    }

    .acs-wan-param-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
    }

    .acs-wan-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
        margin-bottom: 8px;
    }

    .acs-wan-param-item {
        min-width: 0;
    }

    .acs-wan-param-key {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #f68013;
        margin-bottom: 2px;
        word-break: break-word;
    }

    .acs-wan-param-value {
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-wan-empty {
        color: #71717a;
        font-size: 12px;
    }

    .acs-local-hosts-wrap {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-local-hosts-title {
        font-weight: 700;
        margin-bottom: 8px;
        color: #27272a;
    }

    .acs-local-hosts-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .acs-local-host-card {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        line-height: 1.45;
        font-size: 12px;
    }

    .acs-local-host-name {
        font-weight: 700;
        margin-bottom: 4px;
        color: #f68013;
    }

    .acs-local-host-item {
        margin: 2px 0;
        color: #27272a;
        word-break: break-word;
    }

    .acs-raw-toggle-wrap {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-raw-params-list {
        max-height: 260px;
        overflow-y: auto;
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px;
        margin-top: 8px;
        font-size: 11px;
    }

    .acs-raw-param-row {
        display: flex;
        gap: 8px;
        padding: 3px 0;
        border-bottom: 1px dashed #e4e4e7;
        word-break: break-all;
    }

    .acs-raw-param-row .acs-param-key {
        flex: 0 0 55%;
    }

    body.app-theme-dark .acs-local-host-card {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-local-host-name {
        color: #f68013 !important;
    }
    body.app-theme-dark .acs-local-host-item {
        color: #f3f4f6 !important;
    }
    body.app-theme-dark .acs-local-hosts-title {
        color: #f8fafc !important;
    }
    body.app-theme-dark .acs-local-hosts-wrap,
    body.app-theme-dark .acs-raw-toggle-wrap {
        border-top-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-raw-params-list {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-raw-param-row {
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }

    .acs-section-title {
        color: #27272a;
    }

    /* ===== ACS Dark Theme (body.app-theme-dark) ===== */
    body.app-theme-dark .acs-device-card {
        background: #111827 !important;
        color: #e5e7eb !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    body.app-theme-dark .acs-device-card,
    body.app-theme-dark .acs-device-card * {
        color: #e5e7eb !important;
    }
    body.app-theme-dark .acs-device-info-item,
    body.app-theme-dark .acs-ssid-row,
    body.app-theme-dark .acs-wan-card {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-device-info-label,
    body.app-theme-dark .acs-ssid-name,
    body.app-theme-dark .acs-wan-param-key,
    body.app-theme-dark .acs-param-key {
        color: #f68013 !important;
    }
    body.app-theme-dark .acs-device-info-value,
    body.app-theme-dark .acs-ssid-value,
    body.app-theme-dark .acs-wan-param-value,
    body.app-theme-dark .acs-param-value {
        color: #f3f4f6 !important;
    }
    body.app-theme-dark .acs-ssid-title,
    body.app-theme-dark .acs-wan-title,
    body.app-theme-dark .acs-wan-card-title {
        color: #f8fafc !important;
    }
    body.app-theme-dark .acs-ssid-section,
    body.app-theme-dark .acs-wan-section {
        border-top-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-wan-empty,
    body.app-theme-dark .acs-empty-text {
        color: #cbd5e1 !important;
    }
    body.app-theme-dark .acs-section-title {
        color: #f8fafc !important;
    }

    [id^="acs-sync-info-"] {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        font-weight: 700;
        border: 1px solid transparent;
        line-height: 1.2;
    }

    .acs-sync-badge-fresh {
        background: #dcfce7 !important;
        color: #166534 !important;
        border-color: #86efac !important;
    }

    .acs-sync-badge-stale {
        background: #fef9c3 !important;
        color: #854d0e !important;
        border-color: #fde047 !important;
    }

    .acs-sync-badge-expired {
        background: #fee2e2 !important;
        color: #991b1b !important;
        border-color: #fecaca !important;
    }

    body.app-theme-dark .acs-sync-badge-fresh {
        background: #14532d !important;
        color: #dcfce7 !important;
        border-color: #22c55e !important;
    }

    body.app-theme-dark .acs-sync-badge-stale {
        background: #713f12 !important;
        color: #fef9c3 !important;
        border-color: #eab308 !important;
    }

    body.app-theme-dark .acs-sync-badge-expired {
        background: #7f1d1d !important;
        color: #fee2e2 !important;
        border-color: #f87171 !important;
    }

    .acs-ssid-editor-dialog,
    .acs-wan-editor-dialog {
        width: auto !important;
        max-width: 420px !important;
        margin: 1.75rem auto !important;
    }

    .acs-wan-editor-dialog {
        max-width: 520px !important;
    }

    .acs-ssid-editor-content,
    .acs-wan-editor-content {
        flex-direction: column !important;
        display: block !important;
        width: 100% !important;
        max-height: 82vh !important;
        overflow: hidden !important;
        font-size: 14px !important;
    }

    .acs-ssid-editor-body,
    .acs-wan-editor-body {
        overflow-y: auto !important;
        padding: 1rem !important;
    }

    .acs-wan-form-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .acs-wan-form-field {
        min-width: 0;
    }

    .acs-wan-form-field-full {
        grid-column: 1 / -1;
    }

    @media (max-width: 767.98px) {
        .acs-device-info-grid,
        .acs-ssid-grid,
        .acs-local-hosts-grid {
            grid-template-columns: 1fr;
        }

        .acs-wan-summary-grid,
        .acs-wan-param-list {
            grid-template-columns: 1fr;
        }
    }

    .qts-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(2, 6, 23, 0.62);
        /* SENGAJA TANPA backdrop-filter: kalau ancestor punya backdrop-filter/filter,
           Chrome/Edge merender popup dropdown native <select> jadi kosong/tidak muncul
           sama sekali saat diklik (bug rendering nyata, bukan cuma soal blur visual) -
           semua <select> di dalam modal ini (Metode Pembayaran, Bulan Periode, dst)
           kena dampaknya. Efek blur dikorbankan supaya dropdown select tetap berfungsi. */
        z-index: 10000;
        justify-content: center;
        align-items: center;
        padding: 16px;
        pointer-events: auto;
    }

    .qts-modal-overlay[style*="display: flex"] {
        display: flex !important;
    }

    .qts-modal-card {
        width: 420px;
        max-width: 95%;
        background: var(--bs-body-bg, #111827);
        color: var(--bs-body-color, #e5e7eb);
        border: 1px solid rgba(var(--bs-primary-rgb, 37, 99, 235), 0.35);
        border-radius: 12px;
        box-shadow: 0 16px 35px rgba(15, 23, 42, 0.45);
        padding: 20px;
        position: relative;
        z-index: 10001;
        pointer-events: auto;
    }

    .qts-modal-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--bs-heading-color, var(--bs-body-color, #e5e7eb));
        margin-bottom: 14px;
    }

    .qts-modal-card .form-label {
        color: var(--bs-secondary-color, #94a3b8);
        font-weight: 600;
        margin-bottom: 4px;
    }

    .qts-modal-card .form-control,
    .qts-modal-card .form-select,
    .qts-modal-card textarea,
    .qts-modal-card select,
    .qts-modal-card input:not([type="checkbox"]):not([type="radio"]) {
        background: var(--bs-body-bg, #0b1220) !important;
        color: var(--bs-body-color, #e5e7eb) !important;
        border: 1px solid var(--bs-border-color, #334155) !important;
        pointer-events: auto !important;
        position: relative;
        z-index: 10002;
    }

    /* cursor:text hanya untuk field teks - <select> BUKAN field teks, dan cursor
       text di atasnya bikin terlihat seperti tidak bisa diklik/bukan dropdown. */
    .qts-modal-card .form-control:not(select),
    .qts-modal-card textarea,
    .qts-modal-card input:not([type="checkbox"]):not([type="radio"]) {
        cursor: text !important;
    }

    .qts-modal-card .form-control:focus,
    .qts-modal-card .form-select:focus,
    .qts-modal-card textarea:focus,
    .qts-modal-card select:focus,
    .qts-modal-card input:not([type="checkbox"]):not([type="radio"]):focus {
        border-color: var(--logo-secondary, #3b82f6) !important;
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.2) !important;
    }

    .qts-modal-card .form-check-input {
        background-color: var(--bs-body-bg, #0b1220);
        border-color: var(--bs-border-color, #64748b);
    }

    .qts-modal-card .form-check-input:checked {
        background-color: var(--logo-secondary, #3b82f6) !important;
        border-color: var(--logo-secondary, #3b82f6) !important;
    }

    .qts-modal-card .form-check-input:focus {
        border-color: var(--logo-secondary, #3b82f6);
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.2);
    }

    .qts-modal-card .btn {
        font-weight: 600;
        pointer-events: auto !important;
        z-index: 10002;
        position: relative;
    }

    .qts-modal-card form {
        pointer-events: auto !important;
        position: relative;
        z-index: 10001;
    }

    .qts-modal-card form > div {
        pointer-events: auto !important;
    }

    .qts-modal-card .row {
        pointer-events: auto !important;
    }

    .qts-modal-card .col-6 {
        pointer-events: auto !important;
    }

    .qts-modal-card .col-6 > * {
        pointer-events: auto !important;
    }

    .qts-modal-card .mb-2 > input,
    .qts-modal-card .mb-2 > textarea {
        pointer-events: auto !important;
        -webkit-appearance: none;
        appearance: none;
    }

    #addCustomerModal .add-customer-modal-dialog {
        height: 100vh;
        height: 100dvh;
        max-height: 100vh;
        max-height: 100dvh;
        margin: 0 auto;
    }

    #addCustomerModal .add-customer-modal-content {
        height: 100%;
        max-height: 100%;
        display: flex;
        flex-direction: column;
    }

    #addCustomerModal .add-customer-modal-body {
        flex: 0 0 auto;
        display: flex;
        overflow: hidden;
        padding: 0 !important;
    }

    #addCustomerModal .add-customer-iframe {
        width: 100%;
        height: auto;
        min-height: 320px;
        border: 0;
        display: block;
        flex: 1 1 auto;
    }

   
</style>

<!-- Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered add-customer-modal-dialog">
        <div class="modal-content add-customer-modal-content">
            <div class="bg-primary text-white text-center p-3">
                <h4 class="m-0 text-white" id="addCustomerModalLabel">Add Customer PPPOE</h4>
            </div>
            <div class="modal-body p-0 add-customer-modal-body">
                <iframe id="customerIframe" class="add-customer-iframe" src="addcustomerform.php" frameborder="0"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Script -->
<script>
function openAddCustomerModal(prefill) {
    prefill = prefill || {};
    const iframe = document.getElementById('customerIframe');
    const modalEl = document.getElementById('addCustomerModal');
    const titleEl = document.getElementById('addCustomerModalLabel');
    if (!iframe || !modalEl) return;

    const params = new URLSearchParams();
    if (prefill.username) params.set('pppoe_username', prefill.username);
    if (prefill.server) params.set('pppoe_server', prefill.server);
    if (prefill.registerOnly) params.set('register_only', '1');

    const qs = params.toString();
    iframe.src = 'addcustomerform.php' + (qs ? ('?' + qs) : '');

    if (titleEl) {
        titleEl.textContent = prefill.registerOnly ? 'Registrasi PPPoE ke Billing' : 'Add Customer PPPOE';
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

document.addEventListener("DOMContentLoaded", function() {
    const iframe = document.getElementById("customerIframe");
    const modalEl = document.getElementById("addCustomerModal");

    const openBtn = document.getElementById('btnOpenAddCustomerModal');
    if (openBtn) {
        openBtn.addEventListener('click', function() {
            openAddCustomerModal();
        });
    }

    if (!iframe) return;

    function resizeAddCustomerIframe() {
        const contentEl = document.querySelector('#addCustomerModal .add-customer-modal-content');
        const headerEl = document.querySelector('#addCustomerModal .modal-header, #addCustomerModal .bg-primary');
        const footerEl = document.querySelector('#addCustomerModal .modal-footer');
        const bodyEl = document.querySelector('#addCustomerModal .add-customer-modal-body');
        if (!contentEl || !bodyEl) return;

        const contentHeight = contentEl.clientHeight;
        const headerHeight = headerEl ? headerEl.offsetHeight : 0;
        const footerHeight = footerEl ? footerEl.offsetHeight : 0;
        const availableHeight = Math.max(320, Math.floor(contentHeight - headerHeight - footerHeight));

        bodyEl.style.height = availableHeight + 'px';
        iframe.style.height = availableHeight + 'px';
    }

    resizeAddCustomerIframe();
    window.addEventListener('resize', resizeAddCustomerIframe);
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function() {
            resizeAddCustomerIframe();
            requestAnimationFrame(resizeAddCustomerIframe);
        });
    }

    iframe.addEventListener('load', function() {
        resizeAddCustomerIframe();
        try {
            const url = iframe.contentWindow.location.href;
            if (url.includes("tables.php?pesan=berhasil&text=Success")) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                setTimeout(() => {
                    window.location.href = "tables.php?pesan=berhasil&text=Success";
                }, 300);
            }
        } catch (err) {
            console.warn("Tidak dapat membaca URL iframe:", err);
        }
    });
});
</script>


<style>
    .tables-main-card-header {
        background: linear-gradient(135deg, var(--logo-primary, #1d4ed8), var(--logo-secondary, #2563eb)) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.22);
    }

    .tables-main-card-header .tables-main-card-title {
        color: #ffffff !important;
        opacity: 1 !important;
        font-weight: 700;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
        letter-spacing: 0.2px;
    }

    body.app-theme-dark .tables-main-card-header {
        background: linear-gradient(135deg, #0f172a, #1e293b) !important;
        border-bottom-color: rgba(148, 163, 184, 0.3);
    }

    .tables-theme-fix .text-muted,
    .tables-theme-fix .small.text-muted {
        color: var(--bs-secondary-color, #495057) !important;
        opacity: 1 !important;
    }

    body.app-theme-dark .tables-theme-fix .text-muted,
    body.app-theme-dark .tables-theme-fix .small.text-muted {
        color: #cbd5e1 !important;
    }

    .tables-theme-fix .badge {
        font-weight: 700;
        opacity: 1 !important;
    }

    .tables-theme-fix .tables-main-card-header .badge {
        background: rgba(255, 255, 255, 0.94) !important;
        color: #0f172a !important;
        border: 1px solid rgba(15, 23, 42, 0.2);
        text-shadow: none;
    }

    body.app-theme-dark .tables-theme-fix .tables-main-card-header .badge {
        background: #e2e8f0 !important;
        color: #0f172a !important;
        border-color: rgba(148, 163, 184, 0.75);
    }
</style>



    <div class="container-fluid py-4 px-3 px-md-4 tables-theme-fix">

<?php
// Peringatan kalau Payment Setting utk Fixed Due Date belum pernah disimpan
// sama sekali (file notifbot/data/reminder-$ceknama.json belum ada) -- tanpa
// ini, hari jatuh tempo Fixed Due Date & jendela tutup buku diam-diam pakai
// default (jatuh_tempo=25) di seluruh sistem (cron isolir, tables.php,
// activecustomer.php, dst) tanpa admin sadar belum pernah mengaturnya.
$reminderSettingFile = __DIR__ . '/notifbot/data/reminder-' . $ceknama . '.json';
if (!file_exists($reminderSettingFile)):
?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="fas fa-triangle-exclamation"></i>
        <div>
            Anda belum melakukan setting tempo untuk mode tempo <strong>Fixed Due Date</strong>,
            silahkan klik <a href="paymentset.php" class="alert-link">di sini</a> untuk ke halaman Payment Setting.
        </div>
    </div>
<?php endif; ?>

<!-- Card Cron Tiket Maintenance Otomatis dipindahkan ke system_setting.php -->

        <div class="row">
            <div class="col-12">
                <div class="card mb-4 shadow-sm">
                    <div class="card-header pb-0 dashboard-dark-header theme-aware-header tables-main-card-header">
                        <h6 class="mb-0 tables-main-card-title">Customer pppoe</h6>

</div>

                        <?php
                        $raw_success_text = trim(urldecode($_GET['text'] ?? ''));
                        $is_delete_success = (bool) preg_match('/^success\s+delete\b/i', $raw_success_text);
                        if (isset($_GET['pesan']) && $_GET['pesan'] === 'berhasil') {
                            if ($is_delete_success) {
                                $delete_id_raw = trim(urldecode($_GET['idpel'] ?? ''));
                                if ($delete_id_raw === '' && preg_match('/^success\s+delete\b\s*(.+)$/i', $raw_success_text, $delete_match)) {
                                    $delete_id_raw = trim($delete_match[1]);
                                }
                                $delete_id = htmlspecialchars($delete_id_raw, ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                            Success dismantle pelanggan<?= $delete_id !== '' ? ' : ' . $delete_id : '' ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php
                            } else {
                            $reg_idpel    = htmlspecialchars(urldecode($_GET['idpel']    ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_nama     = htmlspecialchars(urldecode($_GET['nama']     ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_pppoe_p  = htmlspecialchars(urldecode($_GET['pppoe_p']  ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_acs_url  = htmlspecialchars(urldecode($_GET['acs_url']  ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_acs_user = htmlspecialchars(urldecode($_GET['acs_user'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_acs_pass = htmlspecialchars(urldecode($_GET['acs_pass'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $reg_text     = htmlspecialchars(urldecode($_GET['text']      ?? 'Registrasi berhasil'), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div id="regSuccessCard" class="alert alert-success border mb-3 p-3 position-relative">
                            <!-- Close button -->
                            <button onclick="document.getElementById('regSuccessCard').style.display='none'"
                                class="btn-close position-absolute" style="top:10px;right:12px;" title="Tutup"></button>

                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span style="font-size:22px;">✅</span>
                                <div>
                                    <strong>REGISTRASI PERANGKAT BERHASIL</strong>
                                    <div class="small text-muted mt-1"><?= $reg_text ?></div>
                                </div>
                            </div>

                            <p class="small text-muted mb-2">
                                Salin pesan berikut lalu kirimkan ke grup untuk menginformasikan teknisi.
                            </p>

                            <div id="regShareMsg" class="border rounded p-2 mb-2 small font-monospace bg-light"
                                style="white-space:pre-wrap;word-break:break-word;line-height:1.65;"><?php
$shareLines = [];
$shareLines[] = '✅ *REGISTRASI PERANGKAT BERHASIL*';
if ($reg_nama !== '') $shareLines[] = '👤 Pelanggan      : ' . $reg_nama;
$shareLines[] = '';
if ($reg_idpel !== '' || $reg_pppoe_p !== '') {
    $shareLines[] = '🌐 *Konfigurasi PPPoE*';
    if ($reg_idpel   !== '') $shareLines[] = 'PPPoE Username : ' . htmlspecialchars($reg_idpel,   ENT_QUOTES, 'UTF-8');
    if ($reg_pppoe_p !== '') $shareLines[] = 'PPPoE Password : ' . htmlspecialchars($reg_pppoe_p, ENT_QUOTES, 'UTF-8');
    $shareLines[] = 'Service list   : TR069 dan INTERNET';
    $shareLines[] = '';
}
if ($reg_acs_url !== '' || $reg_acs_user !== '') {
    $shareLines[] = '📡 *Konfigurasi TR069 ACS*';
    if ($reg_acs_url  !== '') $shareLines[] = 'ACS URL      : ' . htmlspecialchars($reg_acs_url,  ENT_QUOTES, 'UTF-8').'/';
    if ($reg_acs_user !== '') $shareLines[] = 'Username ACS : ' . htmlspecialchars($reg_acs_user, ENT_QUOTES, 'UTF-8');
    if ($reg_acs_pass !== '') $shareLines[] = 'Password ACS : ' . htmlspecialchars($reg_acs_pass, ENT_QUOTES, 'UTF-8');
    $shareLines[] = '';
}
$shareLines[] = '_Mohon segera lakukan konfigurasi perangkat. Terima kasih._';
echo implode("\n", $shareLines);
                                ?></div>
                                <button onclick="copyShareMsg(this)"
                                    class="btn btn-primary btn-sm w-100">
                                    📋 Salin Pesan untuk dikirim ke Grup WhatsApp
                                </button>
                        </div>

                        <script>
                        function copyRegField(elId, btn) {
                            var el = document.getElementById(elId);
                            if (!el) return;
                            var text = el.innerText || el.textContent || '';
                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(text).then(function() {
                                    showCopied(btn);
                                }).catch(function() { fallbackCopy(text, btn); });
                            } else {
                                fallbackCopy(text, btn);
                            }
                        }
                        function fallbackCopy(text, btn) {
                            var ta = document.createElement('textarea');
                            ta.value = text;
                            ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
                            document.body.appendChild(ta);
                            ta.focus(); ta.select();
                            try { document.execCommand('copy'); showCopied(btn); } catch(e) {}
                            document.body.removeChild(ta);
                        }
                        function showCopied(btn) {
                            var orig = btn.innerHTML;
                            btn.innerHTML = '✅ Tersalin';
                            btn.style.background = 'rgba(34,197,94,0.22)';
                            btn.style.borderColor = 'rgba(34,197,94,0.5)';
                            btn.style.color = '#4ade80';
                            setTimeout(function() {
                                btn.innerHTML = orig;
                                btn.style.background = '';
                                btn.style.borderColor = '';
                                btn.style.color = '';
                            }, 2000);
                        }
                        function copyShareMsg(btn) {
                            var el = document.getElementById('regShareMsg');
                            if (!el) return;
                            var text = el.innerText || el.textContent || '';
                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(text).then(function() { showCopied(btn); }).catch(function() { fallbackCopy(text, btn); });
                            } else {
                                fallbackCopy(text, btn);
                            }
                        }
                        </script>
                        <?php
                            }
                        }
                        ?>

                        <div class="container mt-5">
                                                <?php
                                                $export_url = 'export_pelanggan_excel.php';
                                                if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'filter_pelanggan_unified' && !empty($_REQUEST['area'])) {
                                                        $export_url .= '?area=' . urlencode(trim($_REQUEST['area']));
                                                }
                                                ?>
                        <div class="customer-action-toolbar">
                            <button type="button" class="btn btn-primary btn-lg customer-toolbar-btn" id="btnOpenAddCustomerModal">
                               Add Customer PPPOE
                            </button>
                            <button type="button" class="btn btn-secondary btn-lg customer-toolbar-btn" id="btnScanUnregisteredPppoe">
                                <i class="fas fa-search"></i> Scan PPPoE Belum Terdaftar
                            </button>
                            <button type="button" class="btn btn-danger btn-lg customer-toolbar-btn" id="btnScanActiveConnections">
                                <i class="fas fa-bolt"></i> Scan Koneksi Aktif Tidak di DB
                            </button>
                            <a href="importcustomerpppoe.php" class="btn btn-success btn-lg customer-toolbar-btn">
                                <i class="fas fa-file-excel"></i> Import dari Excel
                            </a>
                            <a href="<?= htmlspecialchars($export_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning btn-lg customer-toolbar-btn" id="btnExportPelanggan">
                                <i class="fas fa-file-excel"></i> Export Pelanggan (Excel)
                            </a>
                        </div>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var exportBtn = document.getElementById('btnExportPelanggan');
                            if (!exportBtn) return;

                            exportBtn.addEventListener('click', function(e) {
                                var tbody = document.getElementById('customerTableBody');
                                var hasData = false;
                                if (tbody) {
                                    var rows = tbody.querySelectorAll('tr');
                                    for (var i = 0; i < rows.length; i++) {
                                        if (!rows[i].querySelector('td[colspan]')) {
                                            hasData = true;
                                            break;
                                        }
                                    }
                                }
                                if (!hasData) {
                                    e.preventDefault();
                                    alert('Belum ada data pelanggan yang ditampilkan. Silakan gunakan pencarian atau filter di atas terlebih dahulu sebelum export.');
                                }
                            });
                        });
                        </script>

                        <div class="modal fade" id="scanUnregisteredPppoeModal" tabindex="-1" aria-labelledby="scanUnregisteredPppoeModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header bg-dark text-white">
                                        <h5 class="modal-title text-white" id="scanUnregisteredPppoeModalLabel">Hasil Scan PPPoE Belum Terdaftar</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="scanPppoeSummary" class="alert alert-secondary py-2 mb-3">Klik tombol scan untuk memulai.</div>
                                        <div class="d-flex align-items-center gap-2 mb-2" id="scanBulkActions" style="display:none!important;">
                                            <button type="button" class="btn btn-danger btn-sm" id="btnDeleteSelectedPppoe" disabled>
                                                <i class="fas fa-trash"></i> Hapus Terpilih (<span id="selectedPppoeCount">0</span>)
                                            </button>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle" id="scanUnregisteredPppoeTable">
                                                <thead>
                                                    <tr>
                                                        <th style="width:40px;"><input type="checkbox" id="selectAllPppoe" title="Pilih Semua"></th>
                                                        <th>No</th>
                                                        <th>Username PPPoE</th>
                                                        <th>Profile</th>
                                                        <th>Service</th>
                                                        <th>Status</th>
                                                        <th>Server</th>
                                                        <th>Area</th>
                                                        <th>Last Caller ID</th>
                                                        <th>Last Logout</th>
                                                        <th>Comment</th>
                                                        <th>Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="scanUnregisteredPppoeBody">
                                                    <tr><td colspan="12" class="text-center text-muted">Belum ada data.</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <script>
                            function escapeScanText(text) {
                                return String(text == null ? '' : text)
                                    .replace(/&/g, '&amp;')
                                    .replace(/</g, '&lt;')
                                    .replace(/>/g, '&gt;')
                                    .replace(/"/g, '&quot;')
                                    .replace(/'/g, '&#039;');
                            }

                            function updateSelectedCount() {
                                var count = document.querySelectorAll('.pppoe-select-cb:checked').length;
                                document.querySelectorAll('#selectedPppoeCount').forEach(function(el) { el.textContent = count; });
                                var btn = document.getElementById('btnDeleteSelectedPppoe');
                                if (btn) btn.disabled = count === 0;
                            }

                            function refreshScanSummaryCount() {
                                const tbody = document.getElementById('scanUnregisteredPppoeBody');
                                const summary = document.getElementById('scanPppoeSummary');
                                if (!tbody || !summary) return;

                                const rows = tbody.querySelectorAll('tr[data-pppoe-row="1"]');
                                const totalRows = rows.length;

                                rows.forEach(function(row, idx) {
                                    const firstCell = row.querySelectorAll('td')[1];
                                    if (firstCell) {
                                        firstCell.textContent = String(idx + 1);
                                    }
                                });

                                if (totalRows === 0) {
                                    tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">Semua PPPoE sudah terdaftar di data pelanggan.</td></tr>';
                                }

                                summary.textContent = summary.textContent.replace(/belum terdaftar:\s*\d+/i, 'belum terdaftar: ' + totalRows);
                            }

                            async function runScanUnregisteredPppoe() {
                                const btn = document.getElementById('btnScanUnregisteredPppoe');
                                const serverSelect = document.getElementById('server');
                                const summary = document.getElementById('scanPppoeSummary');
                                const tbody = document.getElementById('scanUnregisteredPppoeBody');
                                const modalEl = document.getElementById('scanUnregisteredPppoeModal');

                                if (!btn || !serverSelect || !summary || !tbody || !modalEl) return;

                                const selectedServer = serverSelect ? (serverSelect.value || '').trim() : '';

                                const selectedLabel = (serverSelect && serverSelect.options[serverSelect.selectedIndex])
                                    ? serverSelect.options[serverSelect.selectedIndex].text
                                    : (selectedServer || 'Semua Server Akses');

                                const modal = new bootstrap.Modal(modalEl);
                                modal.show();

                                btn.disabled = true;
                                const originalHtml = btn.innerHTML;
                                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Scanning...';

                                summary.className = 'alert alert-info py-2 mb-3';
                                summary.textContent = 'Sedang scan PPPoE dari Mikrotik, mohon tunggu...';
                                tbody.innerHTML = '<tr><td colspan="12" class="text-center">Memuat data...</td></tr>';

                                try {
                                    const scanUrl = selectedServer
                                        ? 'getdata/scan_unregistered_pppoe.php?server=' + encodeURIComponent(selectedServer)
                                        : 'getdata/scan_unregistered_pppoe.php';

                                    const response = await fetch(scanUrl, {
                                        credentials: 'same-origin'
                                    });
                                    const result = await response.json();

                                    if (!response.ok || !result.success) {
                                        throw new Error(result.message || 'Gagal melakukan scan.');
                                    }

                                    const data = Array.isArray(result.data) ? result.data : [];
                                    const failedInfo = (result.failed_server_count || 0) > 0
                                        ? ' | gagal konek: ' + result.failed_server_count
                                        : '';
                                    const summaryServerLabel = selectedServer ? selectedLabel : 'Semua Server Akses';

                                    summary.className = 'alert alert-success py-2 mb-3';
                                    summary.textContent = 'Server: ' + summaryServerLabel + ' | diperiksa secret: ' + (result.total_secret_checked || 0) + ' | belum terdaftar: ' + data.length + failedInfo;

                                    if (data.length === 0) {
                                        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted">Semua PPPoE sudah terdaftar di data pelanggan.</td></tr>';
                                        document.getElementById('scanBulkActions').style.display = 'none';
                                    } else {
                                        document.getElementById('scanBulkActions').style.cssText = '';
                                        document.getElementById('selectAllPppoe').checked = false;
                                        updateSelectedCount();
                                        tbody.innerHTML = data.map(function(item, idx) {
                                            const disabled = String(item.disabled || '').toLowerCase() === 'true';
                                            const statusBadge = disabled
                                                ? '<span class="badge bg-danger">Disabled</span>'
                                                : '<span class="badge bg-success">Active</span>';
                                            const deleteBtnHtml = '<button type="button" class="btn btn-danger btn-sm btn-delete-unregistered-pppoe" data-username="' +
                                                escapeScanText(item.username || '') + '" data-server-ip="' +
                                                escapeScanText(item.server_ip || '') + '" data-server-user="' +
                                                escapeScanText(item.server_user || '') + '">Hapus</button>';
                                            const registerBtnHtml = '<button type="button" class="btn btn-primary btn-sm btn-register-unregistered-pppoe me-1" data-username="' +
                                                escapeScanText(item.username || '') + '" data-server-user="' +
                                                escapeScanText(item.server_user || '') + '"><i class="fas fa-user-plus"></i> Register</button>';

                                            return '<tr data-pppoe-row="1">' +
                                                '<td><input type="checkbox" class="pppoe-select-cb" data-username="' + escapeScanText(item.username || '') + '" data-server-ip="' + escapeScanText(item.server_ip || '') + '" data-server-user="' + escapeScanText(item.server_user || '') + '"></td>' +
                                                '<td>' + (idx + 1) + '</td>' +
                                                '<td><strong>' + escapeScanText(item.username || '-') + '</strong></td>' +
                                                '<td>' + escapeScanText(item.profile || '-') + '</td>' +
                                                '<td>' + escapeScanText(item.service || '-') + '</td>' +
                                                '<td>' + statusBadge + '</td>' +
                                                '<td>' + escapeScanText(item.server_ip || '-') + '</td>' +
                                                '<td>' + escapeScanText(item.server_area || '-') + '</td>' +
                                                '<td>' + escapeScanText(item.last_caller_id || '-') + '</td>' +
                                                '<td>' + escapeScanText(item.last_logged_out || '-') + '</td>' +
                                                '<td>' + escapeScanText(item.comment || '-') + '</td>' +
                                                '<td>' + registerBtnHtml + deleteBtnHtml + '</td>' +
                                            '</tr>';
                                        }).join('');
                                    }
                                } catch (error) {
                                    summary.className = 'alert alert-danger py-2 mb-3';
                                    summary.textContent = 'Scan gagal: ' + (error.message || 'Terjadi kesalahan.');
                                    tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Gagal mengambil data scan.</td></tr>';
                                } finally {
                                    btn.disabled = false;
                                    btn.innerHTML = originalHtml;
                                }
                            }

                            document.addEventListener('DOMContentLoaded', function() {
                                const scanBtn = document.getElementById('btnScanUnregisteredPppoe');
                                if (scanBtn) {
                                    scanBtn.addEventListener('click', runScanUnregisteredPppoe);
                                }

                                // Select All checkbox
                                document.getElementById('selectAllPppoe').addEventListener('change', function() {
                                    var checked = this.checked;
                                    document.querySelectorAll('.pppoe-select-cb').forEach(function(cb) { cb.checked = checked; });
                                    updateSelectedCount();
                                });

                                // Update count on individual checkbox change
                                document.addEventListener('change', function(e) {
                                    if (e.target.classList.contains('pppoe-select-cb')) {
                                        updateSelectedCount();
                                        var allCbs = document.querySelectorAll('.pppoe-select-cb');
                                        var allChecked = document.querySelectorAll('.pppoe-select-cb:checked');
                                        document.getElementById('selectAllPppoe').checked = allCbs.length > 0 && allCbs.length === allChecked.length;
                                    }
                                });

                                // Bulk delete button
                                document.getElementById('btnDeleteSelectedPppoe').addEventListener('click', async function() {
                                    var selected = document.querySelectorAll('.pppoe-select-cb:checked');
                                    if (selected.length === 0) return;
                                    if (!confirm('Yakin hapus ' + selected.length + ' PPPoE terpilih dari MikroTik?')) return;

                                    var bulkBtn = this;
                                    bulkBtn.disabled = true;
                                    bulkBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menghapus...';

                                    var items = [];
                                    selected.forEach(function(cb) {
                                        items.push({ username: cb.dataset.username, server_ip: cb.dataset.serverIp, server_user: cb.dataset.serverUser, row: cb.closest('tr') });
                                    });

                                    var successCount = 0, failCount = 0;
                                    for (var i = 0; i < items.length; i++) {
                                        try {
                                            var resp = await fetch('getdata/delete_unregistered_pppoe.php', {
                                                method: 'POST', credentials: 'same-origin',
                                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                                body: new URLSearchParams({ username: items[i].username, server_ip: items[i].server_ip, server_user: items[i].server_user })
                                            });
                                            var res = await resp.json();
                                            if (res.success) { items[i].row.remove(); successCount++; }
                                            else { failCount++; }
                                        } catch(e) { failCount++; }
                                        bulkBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + (i+1) + '/' + items.length;
                                    }

                                    document.getElementById('selectAllPppoe').checked = false;
                                    refreshScanSummaryCount();
                                    updateSelectedCount();
                                    bulkBtn.innerHTML = '<i class="fas fa-trash"></i> Hapus Terpilih (<span id="selectedPppoeCount">0</span>)';
                                    bulkBtn.disabled = false;

                                    if (failCount > 0) {
                                        alert('Berhasil hapus: ' + successCount + ', Gagal: ' + failCount);
                                    }
                                });

                                document.addEventListener('click', function(event) {
                                    const registerBtn = event.target.closest('.btn-register-unregistered-pppoe');
                                    if (!registerBtn) return;

                                    const username = (registerBtn.getAttribute('data-username') || '').trim();
                                    const serverUser = (registerBtn.getAttribute('data-server-user') || '').trim();

                                    const scanModalEl = document.getElementById('scanUnregisteredPppoeModal');
                                    if (scanModalEl) {
                                        const scanModal = bootstrap.Modal.getInstance(scanModalEl);
                                        if (scanModal) scanModal.hide();
                                    }

                                    openAddCustomerModal({ username: username, server: serverUser, registerOnly: true });
                                });

                                document.addEventListener('click', async function(event) {
                                    const deleteBtn = event.target.closest('.btn-delete-unregistered-pppoe');
                                    if (!deleteBtn) return;

                                    const username = (deleteBtn.getAttribute('data-username') || '').trim();
                                    const serverIp = (deleteBtn.getAttribute('data-server-ip') || '').trim();
                                    const serverUser = (deleteBtn.getAttribute('data-server-user') || '').trim();

                                    if (!username || !serverIp || !serverUser) {
                                        alert('Data server PPPoE tidak lengkap.');
                                        return;
                                    }

                                    if (!confirm('Yakin hapus PPPoE "' + username + '" di server ' + serverIp + '?')) {
                                        return;
                                    }

                                    const originalBtnHtml = deleteBtn.innerHTML;
                                    deleteBtn.disabled = true;
                                    deleteBtn.innerHTML = 'Menghapus...';

                                    try {
                                        const response = await fetch('getdata/delete_unregistered_pppoe.php', {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded'
                                            },
                                            body: new URLSearchParams({
                                                username: username,
                                                server_ip: serverIp,
                                                server_user: serverUser
                                            })
                                        });

                                        const result = await response.json();
                                        if (!response.ok || !result.success) {
                                            throw new Error(result.message || 'Gagal menghapus PPPoE.');
                                        }

                                        const currentRow = deleteBtn.closest('tr');
                                        if (currentRow) {
                                            currentRow.remove();
                                        }
                                        refreshScanSummaryCount();
                                    } catch (error) {
                                        alert('Gagal hapus PPPoE: ' + (error.message || 'Terjadi kesalahan.'));
                                        deleteBtn.disabled = false;
                                        deleteBtn.innerHTML = originalBtnHtml;
                                    }
                                });
                            });
                        </script>

                        <!-- Modal Scan Koneksi Aktif Tidak di DB -->
                        <div class="modal fade" id="scanActiveConnectionsModal" tabindex="-1" aria-labelledby="scanActiveConnectionsModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title text-white" id="scanActiveConnectionsModalLabel"><i class="fas fa-bolt me-2"></i>Koneksi Aktif Tidak Ada di Database</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="scanActiveConnSummary" class="alert alert-secondary py-2 mb-3">Klik tombol scan untuk memulai.</div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle" id="scanActiveConnTable">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>No</th>
                                                        <th>Username</th>
                                                        <th>Service</th>
                                                        <th>Caller ID</th>
                                                        <th>IP Address</th>
                                                        <th>Uptime</th>
                                                        <th>Server IP</th>
                                                        <th>Server</th>
                                                        <th>Area</th>
                                                        <th>Aksi</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="scanActiveConnBody">
                                                    <tr><td colspan="10" class="text-center text-muted">Belum ada data.</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                        <button type="button" class="btn btn-danger" id="btnRescanActiveConn"><i class="fas fa-sync-alt me-1"></i>Scan Ulang</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <script>
                            function escActiveConnText(text) {
                                return String(text == null ? '' : text)
                                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                            }

                            async function runScanActiveConnections() {
                                const btn = document.getElementById('btnScanActiveConnections');
                                const rescanBtn = document.getElementById('btnRescanActiveConn');
                                const summary = document.getElementById('scanActiveConnSummary');
                                const tbody = document.getElementById('scanActiveConnBody');
                                const modalEl = document.getElementById('scanActiveConnectionsModal');

                                if (!summary || !tbody || !modalEl) return;

                                const serverSelect = document.getElementById('server');
                                const selectedServer = serverSelect ? (serverSelect.value || '').trim() : '';
                                const selectedLabel = (serverSelect && serverSelect.options[serverSelect.selectedIndex])
                                    ? serverSelect.options[serverSelect.selectedIndex].text
                                    : (selectedServer || 'Semua Server');

                                const modal = new bootstrap.Modal(modalEl);
                                modal.show();

                                if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Scanning...'; }
                                if (rescanBtn) { rescanBtn.disabled = true; rescanBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Scanning...'; }

                                summary.className = 'alert alert-info py-2 mb-3';
                                summary.textContent = 'Sedang scan koneksi aktif dari semua MikroTik, mohon tunggu...';
                                tbody.innerHTML = '<tr><td colspan="10" class="text-center"><span class="spinner-border spinner-border-sm me-2"></span>Memuat data...</td></tr>';

                                try {
                                    const scanUrl = selectedServer
                                        ? 'getdata/scan_active_connections.php?server=' + encodeURIComponent(selectedServer)
                                        : 'getdata/scan_active_connections.php';

                                    const response = await fetch(scanUrl, { credentials: 'same-origin' });
                                    const result = await response.json();

                                    if (!response.ok || !result.success) {
                                        throw new Error(result.message || 'Gagal melakukan scan.');
                                    }

                                    const data = Array.isArray(result.data) ? result.data : [];
                                    const failedInfo = (result.failed_server_count || 0) > 0
                                        ? ' | gagal konek: ' + result.failed_server_count + ' server'
                                        : '';
                                    const summaryServer = selectedServer ? selectedLabel : 'Semua Server';

                                    summary.className = data.length > 0 ? 'alert alert-warning py-2 mb-3' : 'alert alert-success py-2 mb-3';
                                    summary.innerHTML = '<strong>Server:</strong> ' + escActiveConnText(summaryServer) +
                                        ' | <strong>Total koneksi aktif diperiksa:</strong> ' + (result.total_active_checked || 0) +
                                        ' | <strong>Tidak ada di DB:</strong> <span class="badge bg-danger">' + data.length + '</span>' + failedInfo;

                                    if (data.length === 0) {
                                        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted"><i class="fas fa-check-circle text-success me-2"></i>Semua koneksi aktif sudah terdaftar di database.</td></tr>';
                                    } else {
                                        tbody.innerHTML = data.map(function(item, idx) {
                                            const killBtn = '<button type="button" class="btn btn-danger btn-sm btn-kill-active-conn" ' +
                                                'data-active-id="' + escActiveConnText(item.active_id || '') + '" ' +
                                                'data-server-ip="' + escActiveConnText(item.server_ip || '') + '" ' +
                                                'data-server-user="' + escActiveConnText(item.server_user || '') + '" ' +
                                                'data-username="' + escActiveConnText(item.username || '') + '">' +
                                                '<i class="fas fa-times-circle me-1"></i>Kill</button>';

                                            return '<tr data-active-conn-row="1">' +
                                                '<td>' + (idx + 1) + '</td>' +
                                                '<td><strong class="text-danger">' + escActiveConnText(item.username || '-') + '</strong></td>' +
                                                '<td>' + escActiveConnText(item.service || '-') + '</td>' +
                                                '<td>' + escActiveConnText(item.caller_id || '-') + '</td>' +
                                                '<td><code>' + escActiveConnText(item.address || '-') + '</code></td>' +
                                                '<td>' + escActiveConnText(item.uptime || '-') + '</td>' +
                                                '<td><code>' + escActiveConnText(item.server_ip || '-') + '</code></td>' +
                                                '<td>' + escActiveConnText(item.server_user || '-') + '</td>' +
                                                '<td>' + escActiveConnText(item.server_area || '-') + '</td>' +
                                                '<td>' + killBtn + '</td>' +
                                            '</tr>';
                                        }).join('');
                                    }
                                } catch (error) {
                                    summary.className = 'alert alert-danger py-2 mb-3';
                                    summary.textContent = 'Scan gagal: ' + (error.message || 'Terjadi kesalahan.');
                                    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">Gagal mengambil data scan.</td></tr>';
                                } finally {
                                    const originalBtnHtml = '<i class="fas fa-bolt"></i> Scan Koneksi Aktif Tidak di DB';
                                    if (btn) { btn.disabled = false; btn.innerHTML = originalBtnHtml; }
                                    if (rescanBtn) { rescanBtn.disabled = false; rescanBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Scan Ulang'; }
                                }
                            }

                            function refreshActiveConnCount() {
                                const tbody = document.getElementById('scanActiveConnBody');
                                if (!tbody) return;
                                const rows = tbody.querySelectorAll('tr[data-active-conn-row="1"]');
                                rows.forEach(function(row, idx) {
                                    const firstCell = row.querySelector('td:first-child');
                                    if (firstCell) firstCell.textContent = String(idx + 1);
                                });
                                if (rows.length === 0) {
                                    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted"><i class="fas fa-check-circle text-success me-2"></i>Semua koneksi aktif sudah terdaftar di database.</td></tr>';
                                }
                            }

                            document.addEventListener('DOMContentLoaded', function() {
                                const scanActiveBtn = document.getElementById('btnScanActiveConnections');
                                if (scanActiveBtn) {
                                    scanActiveBtn.addEventListener('click', runScanActiveConnections);
                                }

                                const rescanBtn2 = document.getElementById('btnRescanActiveConn');
                                if (rescanBtn2) {
                                    rescanBtn2.addEventListener('click', runScanActiveConnections);
                                }

                                // Kill button handler via event delegation
                                document.addEventListener('click', async function(event) {
                                    const killBtn = event.target.closest('.btn-kill-active-conn');
                                    if (!killBtn) return;

                                    const activeId   = (killBtn.getAttribute('data-active-id') || '').trim();
                                    const serverIp   = (killBtn.getAttribute('data-server-ip') || '').trim();
                                    const serverUser = (killBtn.getAttribute('data-server-user') || '').trim();
                                    const username   = (killBtn.getAttribute('data-username') || '').trim();

                                    if (!activeId || !serverIp || !serverUser) {
                                        alert('Data koneksi tidak lengkap.');
                                        return;
                                    }

                                    if (!confirm('Yakin ingin mematikan koneksi aktif:\n"' + username + '"\ndi server ' + serverIp + '?\n\nUser akan langsung disconnect.')) {
                                        return;
                                    }

                                    const originalHtml = killBtn.innerHTML;
                                    killBtn.disabled = true;
                                    killBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                                    try {
                                        const response = await fetch('getdata/kill_active_connection.php', {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                            body: new URLSearchParams({
                                                active_id:   activeId,
                                                server_ip:   serverIp,
                                                server_user: serverUser
                                            })
                                        });

                                        const result = await response.json();

                                        if (!response.ok || !result.success) {
                                            throw new Error(result.message || 'Gagal mematikan koneksi.');
                                        }

                                        const row = killBtn.closest('tr');
                                        if (row) {
                                            row.style.transition = 'opacity 0.3s';
                                            row.style.opacity = '0';
                                            setTimeout(function() { row.remove(); refreshActiveConnCount(); }, 300);
                                        }
                                    } catch (error) {
                                        alert('Gagal kill koneksi: ' + (error.message || 'Terjadi kesalahan.'));
                                        killBtn.disabled = false;
                                        killBtn.innerHTML = originalHtml;
                                    }
                                });
                            });
                        </script>


                            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
                            <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
                            <!-- Leaflet.js Library -->
                            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                            <style>
                                .input-custom-height {
                                    height: 38px;
                                }

                                .customer-action-toolbar {
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                    gap: 12px;
                                    flex-wrap: wrap;
                                    margin: 10px 0 18px;
                                }

                                .customer-toolbar-btn {
                                    min-width: 220px;
                                    font-size: 1rem;
                                    font-weight: 700;
                                    padding: 0.65rem 1.15rem;
                                    border-radius: 0.65rem;
                                    text-align: center;
                                }

                                @media (max-width: 768px) {
                                    .customer-toolbar-btn {
                                        width: 100%;
                                        min-width: 0;
                                    }
                                }

                                .choices {
                                    margin-bottom: 0;
                                }

                                .input-group .choices,
                                .input-group .choices[data-type*=select-one] {
                                    flex: 1 1 auto;
                                    width: 1%;
                                }

                                .choices[data-type*=select-one] {
                                    width: 100%;
                                }

                                .input-group .choices__inner {
                                    width: 100%;
                                }

                                .choices__inner {
                                    min-height: 38px;
                                    padding: 0.375rem 0.75rem;
                                    border-radius: 0.375rem;
                                    border: 1px solid var(--bs-border-color, #495057) !important;
                                    background: var(--bs-body-bg, #0b1220) !important;
                                    color: var(--bs-body-color, #e9ecef) !important;
                                    font-size: 0.875rem;
                                }

                                .choices__list--single {
                                    padding: 0;
                                }

                                .choices__list--single .choices__item,
                                .choices__input,
                                .choices__input::placeholder {
                                    color: var(--bs-body-color, #e9ecef) !important;
                                    opacity: 1;
                                }

                                .choices__placeholder {
                                    color: var(--bs-secondary-color, rgba(233, 236, 239, 0.75)) !important;
                                    opacity: 1 !important;
                                }

                                .choices[data-type*=select-one]::after {
                                    border-color: var(--logo-secondary, #3b82f6) transparent transparent;
                                    right: 12px;
                                }

                                .choices.is-open[data-type*=select-one]::after {
                                    border-color: transparent transparent var(--logo-secondary, #3b82f6);
                                }

                                .choices__list--dropdown,
                                .choices__list[aria-expanded] {
                                    background: var(--bs-body-bg, #0b1220) !important;
                                    border: 1px solid var(--bs-border-color, #495057) !important;
                                    color: var(--bs-body-color, #e9ecef) !important;
                                    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.25);
                                    z-index: 1050;
                                }

                                .choices__list--dropdown .choices__item,
                                .choices__list[aria-expanded] .choices__item {
                                    color: var(--bs-body-color, #e9ecef);
                                }

                                .choices__list--dropdown .choices__item--selectable.is-highlighted,
                                .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
                                    background-color: rgba(var(--bs-primary-rgb, 13, 110, 253), 0.2) !important;
                                }

                                .choices.is-focused .choices__inner,
                                .choices.is-open .choices__inner {
                                    border-color: var(--logo-secondary, #3b82f6) !important;
                                    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.2);
                                }

                                .choices__input {
                                    background: transparent !important;
                                    margin-bottom: 0;
                                }
                            </style>
                             
                            <style>
                                /* Mode Cepat: sembunyikan seluruh kolom Status (badge online/offline,
                                   tombol Buat Tiket/Live Chat, SLA, dst) dari tabel - bukan cuma stop
                                   cek live-nya. Aksi yang sama tetap bisa diakses lewat modal Overview
                                   pelanggan (klik barisnya). */
                                body.fast-status-mode-active #dataTable thead th:nth-child(3),
                                body.fast-status-mode-active #dataTable tbody td:nth-child(3) {
                                    display: none;
                                }
                            </style>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="toggleFastStatusMode" style="width:3em;height:1.5em;cursor:pointer;">
                                <label class="form-check-label" for="toggleFastStatusMode">
                                    <span id="fastStatusModeLabel">Mode Cepat: OFF</span>
                                    <i class="fas fa-info-circle text-muted ms-1"
                                       title="Kalau ON: kolom Status disembunyikan total dari tabel (loading halaman lebih cepat untuk banyak pelanggan, tidak ada lagi cek terus-menerus ke Mikrotik). Status detail (Online/Offline, Down/Up, IP, dst) serta tombol Buat Tiket/Live Chat tetap bisa diakses lewat modal Overview pelanggan (klik barisnya). Perubahan berlaku setelah halaman di-refresh."></i>
                                </label>
                            </div>
                            <script>
                            (function() {
                                const FAST_MODE_KEY = 'pppoe_fast_status_mode';
                                const toggle = document.getElementById('toggleFastStatusMode');
                                const label = document.getElementById('fastStatusModeLabel');
                                if (!toggle || !label) return;

                                // Dibaca sekali di awal load (dipakai startFetching() di bawah) - toggle
                                // hanya mengubah PENGATURAN untuk load berikutnya, tidak mengubah
                                // baris yang sudah kadung di-antrikan di load saat ini (supaya tidak
                                // perlu logika start/stop antrian yang rumit & rawan bug).
                                window.__fastStatusMode = localStorage.getItem(FAST_MODE_KEY) === '1';
                                toggle.checked = window.__fastStatusMode;
                                label.textContent = 'Mode Cepat: ' + (window.__fastStatusMode ? 'ON' : 'OFF');
                                // Pasang class di awal (sebelum baris tabel di-parse) supaya kolom Status
                                // langsung tersembunyi tanpa "kedip" sempat kelihatan dulu.
                                document.body.classList.toggle('fast-status-mode-active', window.__fastStatusMode);

                                toggle.addEventListener('change', function() {
                                    localStorage.setItem(FAST_MODE_KEY, toggle.checked ? '1' : '0');
                                    label.textContent = 'Mode Cepat: ' + (toggle.checked ? 'ON' : 'OFF');
                                    alert('Pengaturan disimpan. Refresh halaman ini supaya Mode Cepat ' + (toggle.checked ? 'aktif' : 'nonaktif') + '.');
                                });
                            })();
                            </script>

                            <form method="POST" class="mb-2" id="form-filter-pelanggan">
                                <input type="hidden" name="action" value="filter_pelanggan_unified">
                                <label for="input-cari-pelanggan" class="form-label">Pencarian (ID Pelanggan / No WhatsApp / Nama Pelanggan)</label>
                                <div class="input-group mb-2">
                                    <input name="cari_pelanggan" id="input-cari-pelanggan" class="form-control input-custom-height" placeholder="Masukkan ID pelanggan, nomor WhatsApp, atau nama pelanggan" value="<?= htmlspecialchars((string)($_REQUEST['cari_pelanggan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            <?php
                            $odp_options = [];
                            $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

                            // ODP source: always from table `odp` based on selected server + area.
                            if (isset($_REQUEST['server'], $_REQUEST['area']) && $_REQUEST['server'] !== '' && $_REQUEST['area'] !== '') {
                                $selected_server = mysqli_real_escape_string($conn, $_REQUEST['server']);
                                $selected_area = mysqli_real_escape_string($conn, $_REQUEST['area']);
                                $query_odp = "SELECT KODE AS ODP, AREA, NAME AS ODP_NAME
                                              FROM odp
                                              WHERE PEMILIK = '$selected_server'
                                                AND AREA = '$selected_area'";
                                if ($AKSES == 'ASSISTANT') {
                                    $query_odp .= " AND AREA IN ($area_list)";
                                }
                                $query_odp .= " ORDER BY KODE ASC";
                            } elseif ($current_user_id && $AKSES != 'ASSISTANT') {
                                // Saat server/area belum dipilih: tampilkan semua ODP milik user.
                                $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
                                $userServerIds = [];
                                while ($r = mysqli_fetch_assoc($queryServerId)) {
                                    $userServerIds[] = "'" . mysqli_real_escape_string($conn, $r['PEMILIK']) . "'";
                                }
                                $userServerList = count($userServerIds) > 0 ? implode(',', $userServerIds) : "''";
                                $query_odp = "SELECT KODE AS ODP, AREA, NAME AS ODP_NAME
                                              FROM odp
                                              WHERE PEMILIK IN ($userServerList)
                                              ORDER BY AREA ASC, KODE ASC";
                            } elseif ($AKSES == 'ASSISTANT') {
                                // Assistant: tampilkan semua ODP sesuai area akses.
                                $query_odp = "SELECT KODE AS ODP, AREA, NAME AS ODP_NAME
                                              FROM odp
                                              WHERE AREA IN ($area_list)
                                              ORDER BY AREA ASC, KODE ASC";
                            } else {
                                // Fallback admin/role lain: tampilkan semua ODP.
                                $query_odp = "SELECT KODE AS ODP, AREA, NAME AS ODP_NAME
                                              FROM odp
                                              ORDER BY AREA ASC, KODE ASC";
                            }

                            $result_odp = mysqli_query($conn, $query_odp);
                            while ($row = mysqli_fetch_assoc($result_odp)) {
                                $odp_options[] = [
                                    'odp' => $row['ODP'],
                                    'area' => $row['AREA'] ?? '',
                                    'odp_name' => $row['ODP_NAME'] ?? ''
                                ];
                            }
                            ?>
                                <label for="server" >Filter Server</label>
                                <div class="mb-2">
                                    <select class="form-select input-custom-height" id="server" name="server" onchange="setAreaFilter()">
                                        <option value="">-- Pilih SERVER AREA --</option>
                                        <?php
                                       
                                           if ($current_user_id) {
                                                  
            if ($AKSES == 'ASSISTANT') {
             
                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                                        
            } else {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                                        
            }
          } 
                                        
                                        while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                                            $area = htmlspecialchars($rowServer['AREA']);
                                            $selectedServerOpt = (isset($_REQUEST['server']) && $_REQUEST['server'] == $rowServer['PEMILIK']) ? 'selected' : '';
                                            echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" '.$selectedServerOpt.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <input type="hidden" id="area" name="area" value="<?= htmlspecialchars($_REQUEST['area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div hidden class="mb-2">
                                    <label class="form-label">Area</label>
                                    <input type="text" class="form-control" id="area_display" readonly value="<?= htmlspecialchars($_REQUEST['area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                <label for="cariodp" class="form-label">Filter ODP</label>
                                <div class="input-group">
                                    <select class="form-select input-custom-height" id="cariodp" name="cariodp">
                                          <option value="">-- Pilih ODP AREA --</option>
                                            <?php foreach ($odp_options as $opt): 
                                                $odp_val = htmlspecialchars($opt['odp'], ENT_QUOTES, 'UTF-8');
                                                $area_val = htmlspecialchars($opt['area'], ENT_QUOTES, 'UTF-8');
                                                $odp_name_val = htmlspecialchars($opt['odp_name'] ?? '', ENT_QUOTES, 'UTF-8');
                                                $display = $odp_val;
                                                if ($odp_name_val !== '') {
                                                    $display .= ' - ' . $odp_name_val;
                                                }
                                                if ($area_val !== '') {
                                                    $display .= ' (' . $area_val . ')';
                                                }
                                            ?>
                                                <option value="<?= $odp_val ?>" <?= (isset($_REQUEST['cariodp']) && $_REQUEST['cariodp'] == $opt['odp']) ? 'selected' : '' ?>>
                                                    <?= $display ?>
                                                </option>
                                            <?php endforeach; ?>
                                    </select>
                                </div>
                                <label for="caripaket" class="form-label mt-2">Filter Paket</label>
                                <div class="input-group">
                                    <select class="form-select input-custom-height" id="caripaket" name="caripaket">
                                         <option value="">-- Pilih PAKET AREA --</option>
                                        <option value="EXPIRED">EXPIRED</option>
                                        <?php
                                      

    if($AKSES !='ASSISTANT') {
        $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
        $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
        $userServerIds = [];
        while($row = mysqli_fetch_assoc($queryServerId)) {
            $userServerIds[] = "'".$row['PEMILIK']."'";
        }
        $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
        $query = "SELECT * FROM paket WHERE `PEMILIK` IN ($userServerList)";
    } else {
        // Untuk ASSISTANT, tetap gunakan $server_list dan filter area_list
        $query = "SELECT * FROM paket WHERE `AREA` IN ($area_list)";
    }



                                        $result = mysqli_query($conn, $query);
                                        $caripaketRows = reseller_filter_rows($conn, reseller_collect_rows($result), 'broadband');
                                        $caripaketSeen = [];
                                        foreach ($caripaketRows as $row) {
                                            $paketshow = htmlspecialchars($row['PAKET'], ENT_QUOTES, 'UTF-8');
                                            if (isset($caripaketSeen[$paketshow])) {
                                                continue;
                                            }
                                            $caripaketSeen[$paketshow] = true;
                                            echo '<option value="' . $paketshow . '" ' . ((isset($_REQUEST['caripaket']) && $_REQUEST['caripaket'] == $paketshow) ? 'selected' : '') . '>' . $paketshow . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-warning input-custom-height w-100">Cari</button>
                                </div>
                            </form>
                            <script>
                            // Cegah spasi di awal dan akhir input pencarian sebelum submit
                            document.addEventListener('DOMContentLoaded', function() {
                                var filterForm = document.getElementById('form-filter-pelanggan');
                                var keywordInput = document.getElementById('input-cari-pelanggan');
                                if (filterForm && keywordInput) {
                                    filterForm.addEventListener('submit', function() {
                                        keywordInput.value = keywordInput.value.trim();
                                    });
                                }

                                var serverSelect = document.getElementById('server');
                                if (serverSelect) {
                                    serverSelect.addEventListener('change', function() {
                                        setAreaFilter();
                                        updateOdpForServer(this.value);
                                    });

                                    if (serverSelect.value) {
                                        setAreaFilter();
                                        updateOdpForServer(serverSelect.value);
                                    }
                                }

                                // Ikutkan filter yang sedang aktif (search/server/area/odp/paket) saat
                                // tombol "Tampilkan Pelanggan LOS" ditekan, supaya hasil LOS tetap
                                // mengikuti filter, bukan menampilkan semua pelanggan LOS.
                                var losForm = document.getElementById('form-carilos');
                                if (losForm) {
                                    losForm.addEventListener('submit', function() {
                                        var areaEl = document.getElementById('area');
                                        var odpEl = document.getElementById('cariodp');
                                        var paketEl = document.getElementById('caripaket');

                                        document.getElementById('carilos-cari-pelanggan').value = keywordInput ? keywordInput.value.trim() : '';
                                        document.getElementById('carilos-server').value = serverSelect ? serverSelect.value : '';
                                        document.getElementById('carilos-area').value = areaEl ? areaEl.value : '';
                                        document.getElementById('carilos-cariodp').value = odpEl ? odpEl.value : '';
                                        document.getElementById('carilos-caripaket').value = paketEl ? paketEl.value : '';
                                    });
                                }
                            });
                            </script>
                            <form method="POST" class="mb-2" id="form-carilos">
                                <input type="hidden" name="action" value="cari_los">
                                <input type="hidden" name="cari_pelanggan" id="carilos-cari-pelanggan">
                                <input type="hidden" name="server" id="carilos-server">
                                <input type="hidden" name="area" id="carilos-area">
                                <input type="hidden" name="cariodp" id="carilos-cariodp">
                                <input type="hidden" name="caripaket" id="carilos-caripaket">
                                <div class="mt-3">
                                <button type="submit" class="btn btn-danger input-custom-height w-100">
                                    Tampilkan Pelanggan LOS
                                </button>
                                 </div>
                            </form>
                            <script>
                            window.tablesSelectChoices = window.tablesSelectChoices || {};
                            window.tablesInitialOdpHtml = window.tablesInitialOdpHtml || '';

                            function applyTablesChoiceSelect(selectId, placeholderText) {
                                var selectElement = document.getElementById(selectId);
                                if (!selectElement || !window.Choices) return;

                                if (window.tablesSelectChoices[selectId]) {
                                    window.tablesSelectChoices[selectId].destroy();
                                }

                                window.tablesSelectChoices[selectId] = new Choices(selectElement, {
                                    searchEnabled: true,
                                    shouldSort: false,
                                    itemSelectText: '',
                                    searchPlaceholderValue: placeholderText,
                                    noResultsText: 'Data tidak ditemukan',
                                    noChoicesText: 'Tidak ada pilihan'
                                });
                            }

                            document.addEventListener('DOMContentLoaded', function() {
                                applyTablesChoiceSelect('cariodp', 'Cari kode ODP atau nama ODP...');
                                applyTablesChoiceSelect('caripaket', 'Cari paket...');
                                applyTablesChoiceSelect('server', 'Cari product/server...');

                                var initialOdp = document.getElementById('cariodp');
                                if (initialOdp && !window.tablesInitialOdpHtml) {
                                    window.tablesInitialOdpHtml = initialOdp.innerHTML;
                                }
                            });
                            </script>
                            <hr>
                            <hr>
                            <script>
                            function setAreaFilter() {
                                let serverSelect = document.getElementById('server');
                                let areaInput = document.getElementById('area');
                                let areaDisplay = document.getElementById('area_display');
                                let selected = serverSelect.options[serverSelect.selectedIndex];
                                let area = selected ? selected.getAttribute('data-area') : '';
                                areaInput.value = area;
                                areaDisplay.value = area;
                            }
                            </script>
                            <script>
                            // Update ODP dropdown based on selected server (pemilik)
                            function updateOdpForServer(server) {
                                const odpSelect = document.getElementById('cariodp');
                                const areaInput = document.getElementById('area');
                                const area = areaInput ? (areaInput.value || '').trim() : '';
                                if (!odpSelect) return;
                                if (!server || server === '' || !area) {
                                    // Jika server/area belum dipilih, kembalikan ke daftar awal (semua ODP milik user).
                                    odpSelect.innerHTML = window.tablesInitialOdpHtml || '<option value="">Pilih KODE ODP</option>';
                                    if (typeof applyTablesChoiceSelect === 'function') {
                                        applyTablesChoiceSelect('cariodp', 'Cari kode ODP atau nama ODP...');
                                    }
                                    return;
                                }
                                fetch('getdata/get_odp.php?server=' + encodeURIComponent(server) + '&area=' + encodeURIComponent(area))
                                    .then(resp => resp.text())
                                    .then(html => {
                                        odpSelect.innerHTML = html;
                                        if (typeof applyTablesChoiceSelect === 'function') {
                                            applyTablesChoiceSelect('cariodp', 'Cari kode ODP atau nama ODP...');
                                        }
                                    })
                                    .catch(err => {
                                        console.error('Gagal memuat ODP:', err);
                                    });
                            }

                            document.addEventListener('DOMContentLoaded', function() {
                                const serverSelect = document.getElementById('server');
                                if (serverSelect) {
                                    serverSelect.addEventListener('change', function() {
                                        setAreaFilter();
                                        updateOdpForServer(this.value);
                                    });

                                    // load initial ODP for current server selection (if any)
                                    if (serverSelect.value) {
                                        setAreaFilter();
                                        updateOdpForServer(serverSelect.value);
                                    }
                                }
                            });
                            </script>
<input type="text" id="searchInput" placeholder="🔍 Sortir data..." class="form-control mb-3">

                            <script>
                                function loadArea() {
                                    const selectedServer = document.getElementById("server").value;
                                    const areaDropdown = document.getElementById("area");
                                    const odpDropdown = document.getElementById("odp");
                                    const packageDropdown = document.getElementById("packages");

                                    // Reset dropdown isi
                                    if (areaDropdown) areaDropdown.innerHTML = '<option value="">Loading...</option>';
                                    if (odpDropdown) odpDropdown.innerHTML = '<option value="">Loading...</option>';
                                    if (packageDropdown) packageDropdown.innerHTML = '<option value="">Loading...</option>';

                                    if (selectedServer !== "") {
                                        const xhr = new XMLHttpRequest();
                                        xhr.open("GET", "getdata/get_area.php?server=" + encodeURIComponent(selectedServer), true);
                                        xhr.onreadystatechange = function() {
                                            if (xhr.readyState === 4 && xhr.status === 200) {
                                                if (areaDropdown) areaDropdown.innerHTML = xhr.responseText;
                                            }
                                        };
                                        xhr.send();
                                    }
                                }
                            </script>



















                    <script>
                        function updateCustomerRowNumbers() {
                            var tbody = document.getElementById("customerTableBody");
                            if (!tbody) return;

                            var rows = tbody.querySelectorAll("tr");
                            var number = 1;

                            rows.forEach(function(row) {
                                if (row.querySelector("td[colspan]")) {
                                    return;
                                }

                                var firstCell = row.querySelector("td");
                                if (!firstCell) return;

                                var numberEl = firstCell.querySelector(".row-number-cell");
                                if (!numberEl) {
                                    numberEl = document.createElement("span");
                                    numberEl.className = "row-number-cell";
                                    firstCell.appendChild(numberEl);
                                }

                                if (row.style.display === "none" || row.hidden) {
                                    numberEl.textContent = "";
                                    return;
                                }

                                numberEl.textContent = String(number++);
                            });
                        }

                        function initCustomerQuickSort() {
                            var searchInput = document.getElementById("searchInput");
                            var tbody = document.getElementById("customerTableBody");
                            if (!searchInput || !tbody) return;
                            if (searchInput.dataset.quickSortBound === "1") return;

                            searchInput.dataset.quickSortBound = "1";

                            searchInput.addEventListener("input", function() {
                                var filter = searchInput.value.toUpperCase();
                                var rows = tbody.querySelectorAll("tr");

                                rows.forEach(function(row) {
                                    if (row.querySelector("td[colspan]")) {
                                        row.style.display = "";
                                        return;
                                    }

                                    var cells = row.querySelectorAll("td");
                                    var found = false;
                                    cells.forEach(function(cell) {
                                        if (found) return;
                                        var txtValue = cell.textContent || cell.innerText || "";
                                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                                            found = true;
                                        }
                                    });

                                    row.style.display = found ? "" : "none";
                                });

                                updateCustomerRowNumbers();

                                // Tunggu sebentar lalu scroll ke atas
                                setTimeout(function() {
                                    window.scrollTo(0, 0);
                                    document.documentElement.scrollTop = 0;
                                    document.body.scrollTop = 0;
                                }, 10);
                            });

                            var rowObserver = new MutationObserver(function() {
                                updateCustomerRowNumbers();
                            });

                            rowObserver.observe(tbody, {
                                childList: true,
                                attributes: true,
                                attributeFilter: ["style", "hidden"]
                            });

                            updateCustomerRowNumbers();
                        }

                        if (document.readyState === "loading") {
                            document.addEventListener("DOMContentLoaded", initCustomerQuickSort);
                        } else {
                            initCustomerQuickSort();
                        }
                    </script>
                 <script>
let trafficHistory = {}; // Menyimpan riwayat data per ID pelanggan

// Ambil RX/Tx dBm dari semua file onulist sesuai server list.
// Fallback ke pencocokan PPPoE (IDPEL) jika MAC tidak ditemukan.
async function getDbmFromOnulist(mac, serverListStr, idPel) {
    let macPrefix = mac.split(':').slice(0,5).join(':'); // ambil 5 byte pertama
    let pppoeNeedle = String(idPel || '').trim().toLowerCase();
    let servers = serverListStr.replace(/'/g, '').split(',').map(s => s.trim());

    try {
        let fileRes = await fetch('getdata/list_onulist_files.php'); 
        let fileList = await fileRes.json();

        for (let fileName of fileList) {
            if (servers.some(s => fileName.includes(s))) {
                try {
                    let res = await fetch(`getdata/getonulist.php?file=${fileName}`);
                    let text = await res.text();
                    let lines = text.split('\n');
                    for (let line of lines) {
                        const lineLower = line.toLowerCase();
                        const matchByMac = macPrefix && lineLower.includes(macPrefix.toLowerCase());
                        const matchByPppoe = pppoeNeedle && (
                            lineLower.includes(`pppoe: ${pppoeNeedle}`) ||
                            lineLower.includes(`| nama: ${pppoeNeedle} |`) ||
                            lineLower.includes(`,${pppoeNeedle},`)
                        );

                        if (!matchByMac && !matchByPppoe) continue;

                        let dataPart = line.split('|').find(p => p.trim().startsWith('Data:'));
                        if (dataPart) {
                            let values = dataPart.replace('Data:', '').split(',');
                            let rxDbm = parseFloat(values[values.length - 2]) || 0;
                            let txDbm = parseFloat(values[values.length - 1]) || 0;
                            return { rxDbm, txDbm, file: fileName };
                        }
                    }
                } catch(e) {
                    console.error(`Error reading ${fileName}:`, e);
                }
            }
        }
    } catch(e) {
        console.error(e);
    }

    return { rxDbm: 0, txDbm: 0, file: null };
}


// Baris detail tambahan (kuota, uptime, link up/down, pemakaian) - dipakai di kondisi online & offline
// idPel + isModal opsional: kalau diisi, span Uptime dikasih id (data-uptime-${idPel}
// / data-uptime-modal-${idPel}) supaya refreshLiveTraffic() bisa update nilainya
// langsung (realtime, sama seperti Down/Up) tanpa perlu render ulang seluruh blok ini.
function buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, isModal) {
    const uptimeIdAttr = idPel ? ` id="data-uptime${isModal ? '-modal' : ''}-${idPel}"` : '';
    return `
        <div class="status-detail-row"><span class="status-detail-label">Kuota</span><span class="status-detail-value">${kuota || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Pemakaian</span><span class="status-detail-value">${pemakaian || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Uptime</span><span class="status-detail-value"${uptimeIdAttr}>${uptime || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Link Up</span><span class="status-detail-value">${linkUp || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Link Down</span><span class="status-detail-value">${linkDown || 'N/A'}</span></div>`;
}

// Badge "Offline (RADIUS)" -- dipakai saat customerMode mengandung RADIUS (RADIUS
// MODE/MULTI MODE) dan koneksi ke Mikrotik API gagal/timeout. Server RADIUS-only
// memang wajar tidak selalu punya API Mikrotik yang bisa dihubungi (kredensial yang
// tersimpan di `server.PASSWORD` untuk mode ini adalah RADIUS shared secret, bukan
// password API), jadi ini BUKAN error sungguhan -- disamakan bentuknya dgn badge
// "Offline (LOS)" yang dipakai LOCAL/API (bg-gradient-danger), BUKAN badge merah
// "Error"/teks "Timeout - server tidak merespon" generik dari renderFetchErrorUI().
function renderOfflineRadiusUI(idPel) {
    const retryBtn = `<button type="button" class="btn btn-link btn-sm p-0 ms-1" style="text-decoration:none;" onclick="retryFetchData('${idPel}')" title="Muat ulang status"><i class="fas fa-sync-alt"></i></button>`;
    const statusHtml = `<span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>${retryBtn}`;

    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = statusHtml;
        const realtimeEl = document.getElementById(`data-realtime-${idPel}`);
        if (realtimeEl) realtimeEl.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>';

        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = statusHtml;
        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
        if (modalInfoEl) modalInfoEl.innerHTML = '<br><span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>';
    } catch (e) {}
    // Catatan: dulu di sini juga panggil cekRadwhoFallback() (dump mentah command
    // radwho) -- sudah dihapus krn get_cached_pppoe_status.php sekarang punya
    // fallback radacct sendiri yang lebih reliable & terformat rapi, sehingga
    // fungsi ini seharusnya sudah jarang terpanggil (cuma network error/timeout
    // sungguhan, bukan lagi krn Mikrotik connect gagal).
}

// Badge error + tombol reload manual (ikon berputar) dipakai di semua jalur error fetchData.
function renderFetchErrorUI(idPel, message) {
    const retryBtn = `<button type="button" class="btn btn-link btn-sm p-0 ms-1" style="text-decoration:none;" onclick="retryFetchData('${idPel}')" title="Muat ulang status"><i class="fas fa-sync-alt"></i></button>`;
    const statusHtml = `<span class="badge badge-sm bg-danger">Error</span>${retryBtn}`;

    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = statusHtml;
        const realtimeEl = document.getElementById(`data-realtime-${idPel}`);
        if (realtimeEl) realtimeEl.innerHTML = `<span class="badge badge-sm bg-danger">${message}</span>`;

        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = statusHtml;
        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
        if (modalInfoEl) modalInfoEl.innerHTML = `<br><span class="badge badge-sm bg-danger">${message}</span>`;
    } catch (e) {}
}

window.__customerFetchParams = window.__customerFetchParams || {};

// Dipanggil dari tombol reload manual saat status gagal dimuat.
function retryFetchData(idPel) {
    const p = window.__customerFetchParams[idPel];
    if (!p) return;
    const loadingHtml = '<span class="badge badge-sm bg-gradient-warning"><i class="fas fa-sync-alt fa-spin"></i> Memuat ulang...</span>';
    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = loadingHtml;
        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = loadingHtml;
    } catch (e) {}
    fetchData(idPel, p.ip, p.us, p.ps, p.mode);
}

// Grafik "Customer Traffic" (canvas trafficChart{idPel}) diisi dari sumber data LIVE
// yang sama dengan angka Down/Up (lihat refreshLiveTraffic di bawah) - supaya grafiknya
// benar-benar real-time dan angkanya tidak beda dengan Down/Up yang tampil di layar.
// (trafficHistory sudah dideklarasikan lebih awal di file ini.)
function updateTrafficChart(idPel, downloadVal, uploadVal) {
    const chartEl = document.getElementById(`trafficChart${idPel}`);
    const ctx = (chartEl && chartEl.tagName === 'CANVAS') ? chartEl.getContext('2d') : null;
    if (!ctx) return;

    const downloadNum = typeof downloadVal === 'number' ? downloadVal : (parseFloat(downloadVal) || 0);
    const uploadNum = typeof uploadVal === 'number' ? uploadVal : (parseFloat(uploadVal) || 0);

    if (!trafficHistory[idPel]) {
        trafficHistory[idPel] = { labels: [], download: [], upload: [] };
    }
    const history = trafficHistory[idPel];

    if (history.labels.length >= 10) {
        history.labels.shift();
        history.download.shift();
        history.upload.shift();
    }

    history.labels.push(new Date().toLocaleTimeString());
    history.download.push(downloadNum);
    history.upload.push(uploadNum);

    try {
        const existingChart = window[`trafficChartInstance${idPel}`];
        // Pakai chart yang sudah ada HANYA kalau canvas-nya masih persis elemen yang sama
        // di DOM sekarang. Kalau canvas-nya sempat diganti (mis. baris customer di-render
        // ulang saat lazy-load/filter), referensi chart lama jadi nyasar ke canvas yang
        // sudah lepas dari DOM - buat ulang chart-nya supaya nempel ke canvas yang benar-benar
        // tampil sekarang.
        if (existingChart && existingChart.canvas === chartEl) {
            // Update data chart yang sudah ada saja - JANGAN destroy+new Chart() di setiap
            // polling. Chart.js responsive:true memicu resize/reflow tiap kali chart dibuat
            // ulang, dan reflow itu memaksa browser menutup paksa <select> native yang lagi
            // terbuka di modal lain (mis. Manual Active) - kelihatan sebagai dropdown select
            // yang kedip tampil-hilang saat diklik.
            existingChart.data.labels = history.labels;
            existingChart.data.datasets[0].data = history.download;
            existingChart.data.datasets[1].data = history.upload;
            existingChart.update('none');
        } else {
            if (existingChart) {
                try { existingChart.destroy(); } catch (destroyErr) { /* canvas lama sudah lepas, abaikan */ }
            }
            window[`trafficChartInstance${idPel}`] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: history.labels,
                    datasets: [
                        { label: 'Download (Mbps)', data: history.download, borderColor: 'blue', backgroundColor: 'rgba(0,0,255,0.2)', fill: true },
                        { label: 'Upload (Mbps)', data: history.upload, borderColor: 'green', backgroundColor: 'rgba(0,128,0,0.2)', fill: true }
                    ]
                },
                options: { responsive: true, scales: { y: { beginAtZero: true } } }
            });
        }
    } catch (chartErr) {
        console.error(`Chart update error for ${idPel}:`, chartErr);
    }
}

// Ambil kecepatan Down/Up secara live (monitor-traffic instan ke Mikrotik), terpisah
// dari status Online/Offline yang sudah instan lewat cache. Sengaja tidak di-await
// oleh fetchData supaya status/badge tidak ikut menunggu koneksi live ini selesai.
const liveTrafficState = {};

async function refreshLiveTraffic(idPel, ipServer, userServer, passwordServer) {
    // Cegah numpuk kalau siklus sebelumnya untuk pelanggan yang sama belum selesai.
    if (liveTrafficState[idPel]) return;
    liveTrafficState[idPel] = true;

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);

        const response = await fetch('getdata/get_live_traffic.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }),
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        const data = await response.json().catch(() => null);
        if (!data) return;

        const download = (data.download !== undefined && data.download !== null) ? data.download : 'Null';
        const upload = (data.upload !== undefined && data.upload !== null) ? data.upload : 'Null';
        const text = `${download} / ${upload} Mbps`;

        const tableEl = document.getElementById(`data-downup-${idPel}`);
        if (tableEl) tableEl.textContent = text;

        updateTrafficChart(idPel, data.download, data.upload);

        const modalEl = document.getElementById(`data-downup-modal-${idPel}`);
        if (modalEl) modalEl.textContent = text;

        // IP & Uptime realtime (sama seperti Down/Up) - cuma ditimpa kalau memang
        // dapat nilai baru dari router. Kalau kosong (mis. sesi barusan putus pas
        // dicek), biarkan nilai lama dari cache tetap tampil daripada ditimpa
        // "N/A"/kosong yang justru kurang informatif.
        if (data.remote_ip) {
            const ipTableEl = document.getElementById(`data-ip-${idPel}`);
            if (ipTableEl) ipTableEl.textContent = data.remote_ip;
            const ipModalEl = document.getElementById(`data-ip-modal-${idPel}`);
            if (ipModalEl) ipModalEl.textContent = data.remote_ip;
        }
        if (data.uptime) {
            const uptimeTableEl = document.getElementById(`data-uptime-${idPel}`);
            if (uptimeTableEl) uptimeTableEl.textContent = data.uptime;
            const uptimeModalEl = document.getElementById(`data-uptime-modal-${idPel}`);
            if (uptimeModalEl) uptimeModalEl.textContent = data.uptime;
        }
    } catch (e) {
        console.warn(`Live traffic error for ${idPel}:`, e);
    } finally {
        liveTrafficState[idPel] = false;
    }
}

function fetchData(idPel, ipServer, userServer, passwordServer, customerMode) {
    try {
        if (!idPel || !ipServer) {
            console.error('Missing required parameters');
            return Promise.resolve();
        }

        // Jangan biarkan menggantung tanpa batas kalau router lambat/mati —
        // 15 detik cukup untuk RouterOS API yang sehat, di atas itu anggap
        // gagal supaya tombol reload manual muncul, bukan "Loading..." selamanya.
        const fetchController = new AbortController();
        const fetchTimeoutId = setTimeout(() => fetchController.abort(), 15000);

        return fetch('getdata/get_cached_pppoe_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }),
            signal: fetchController.signal
        })
        .then(response => {
            clearTimeout(fetchTimeoutId);
            if (!response) throw new Error('No response');
            return response.json().catch(() => null);
        })
        .then(async data => {
            try {
                if (!data) {
                    throw new Error('No data received');
                }
                
                // Grafik "Customer Traffic" TIDAK lagi diisi dari data.download/data.upload di sini -
                // itu nilai dari CACHE (rata-rata per siklus cron, sering 0/basi). Grafik sekarang
                // diisi dari refreshLiveTraffic() (lihat updateTrafficChart()) yang pakai sumber data
                // live yang SAMA dengan angka Down/Up di layar, supaya grafiknya benar-benar real-time
                // dan angkanya konsisten dengan Down/Up.

                let macToCheck = data.status === "Online" ? (data.active_caller_id || 'N/A') : (data.last_caller_secret || 'N/A');

                // Ambil RX/TX dBm dari semua file onulist
                let serverListStr = <?php echo json_encode(isset($server_list) ? (string)$server_list : ''); ?>;
                let rxTxDbm = null;
                try {
                    rxTxDbm = await getDbmFromOnulist(macToCheck, serverListStr, idPel);
                } catch (e) {
                    console.warn(`DBM error for ${idPel}:`, e);
                    rxTxDbm = { rxDbm: 0, txDbm: 0, file: null };
                }
                if (!rxTxDbm) rxTxDbm = { rxDbm: 0, txDbm: 0, file: null };

                // Tentukan badge dan teks RX dBm (kurang dari -27 = merah/lemah, diatas -27 = hijau/baik)
                let rxBadge = 'bg-secondary';
                let rxDisplay = 'Null';
                
                try {
                    if (rxTxDbm && rxTxDbm.rxDbm && rxTxDbm.rxDbm !== 0) {
                        rxBadge = rxTxDbm.rxDbm < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success';
                        rxDisplay = rxTxDbm.rxDbm;
                    }
                } catch (e) {
                    console.warn('RX display error:', e);
                    rxDisplay = 'Null';
                }

                // Ambil RX dBm dari ACS data
                let rxRedaman = 'Null';
                let rxRedamanBadge = 'bg-secondary';
                try {
                    let acsResponse = await fetch('getdata/acs_cache_data.php?idpel=' + encodeURIComponent(idPel));
                    if (!acsResponse) throw new Error('No ACS response');
                    let acsData = await acsResponse.json().catch(() => null);
                    if (acsData && acsData.devices && Array.isArray(acsData.devices) && acsData.devices.length > 0) {
                        let firstDevice = acsData.devices[0];
                        rxRedaman = (firstDevice && firstDevice.rx_power) ? firstDevice.rx_power : 'Null';
                    }
                    if (rxRedaman !== 'Null') {
                        const rxRedamanVal = parseFloat(rxRedaman);
                        if (!isNaN(rxRedamanVal)) {
                            rxRedamanBadge = rxRedamanVal < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success';
                        }
                    }
                } catch (e) {
                    console.error('ACS error:', e);
                    rxRedaman = 'Null';
                }

                // Ambil paket/profile aktif:
                // - LOCAL (login_via = 'local'): ambil dari secret profile (data.cekexpired)
                // - RADIUS (login_via = 'radius' atau 'unknown' saat offline): ambil dari user file RADIUS, fallback ke secret
                let paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                const loginViaForProfile = (data.login_via || '').toLowerCase();

                // MODE tersimpan di DB pelanggan (RADIUS MODE/API MODE/MULTI MODE) dipakai
                // sebagai sinyal tambahan: flag "radius" live dari Mikrotik ("login_via")
                // bisa salah/basi (mis. pelanggan RADIUS MODE tapi masih ada secret lokal
                // sisa sehingga Mikrotik melaporkan login lokal), sehingga paket aktif
                // salah dibaca dari secret lokal yang kosong (jadi N/A) padahal harusnya
                // dari Mikrotik-Group RADIUS.
                const isRadiusConfigured = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
                const localProfileEmpty = ['', 'N/A', 'NULL'].includes(paketAktifRaw.toUpperCase());

                if (loginViaForProfile === 'local' && !isRadiusConfigured && !localProfileEmpty) {
                    // LOCAL murni (bukan pelanggan RADIUS/MULTI MODE) & profile secret ada isinya
                    paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                } else {
                    // RADIUS, tidak diketahui (offline), pelanggan RADIUS/MULTI MODE, atau
                    // profile lokal kosong: coba ambil dari RADIUS user file (Mikrotik-Group),
                    // fallback ke secret kalau tidak ketemu.
                    try {
                        let radiusResponse = await fetch('getdata/getpackagefromradius.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ idpel: idPel })
                        });
                        if (radiusResponse && radiusResponse.ok) {
                            let radiusData = await radiusResponse.json().catch(() => null);
                            if (radiusData && radiusData.package && radiusData.package !== 'Null') {
                                paketAktifRaw = radiusData.package;
                                console.log(`[${idPel}] Profile from RADIUS user file: ${paketAktifRaw}`);
                            } else {
                                // RADIUS tidak ada data, fallback ke secret profile
                                paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                                console.log(`[${idPel}] Profile fallback to secret: ${paketAktifRaw}`);
                            }
                        }
                    } catch (e) {
                        console.warn(`Could not fetch package from RADIUS for ${idPel}:`, e);
                        paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                    }
                }

                const isPaketExpired = String(paketAktifRaw).trim().toUpperCase() === "EXPIRED";
                const paketAktifBadgeClass = isPaketExpired
                    ? 'badge badge-sm bg-gradient-danger'
                    : 'badge badge-sm bg-gradient-success';
                const paketAktifHtml = `<span class="${paketAktifBadgeClass}">${paketAktifRaw}</span>`;

                // Tampilkan profile aktif (dari secret LOCAL atau user file RADIUS) - hanya nilainya saja
                try {
                    const paketAktifEl = document.getElementById(`data-paket-aktif-${idPel}`);
                    if (paketAktifEl) paketAktifEl.innerHTML = paketAktifHtml;

                    const paketAktifModalEl = document.getElementById(`data-paket-aktif-modal-${idPel}`);
                    if (paketAktifModalEl) paketAktifModalEl.innerHTML = paketAktifHtml;
                } catch (e) {
                    console.warn(`Paket aktif display error for ${idPel}:`, e);
                }

                // Safe element access
                let statusElement2 = null;
                let realtimeElement = null;
                
                try {
                    statusElement2 = document.getElementById(`data-status2-${idPel}`) || null;
                    realtimeElement = document.getElementById(`data-realtime-${idPel}`) || null;
                } catch (e) {
                    console.warn(`Element access error for ${idPel}:`, e);
                }

                if (data.status === "Online") {
                    try {
                        // Live flag dari Mikrotik bisa salah/basi untuk pelanggan RADIUS/MULTI
                        // MODE (mis. ada secret lokal sisa) -- kalau MODE pelanggan bilang RADIUS
                        // tapi live flag bilang local, tampilkan RADIUS supaya badge status
                        // konsisten dengan sumber paket aktif di atas (sudah diarahkan ke RADIUS
                        // untuk kasus ini juga).
                        const rawLoginVia = (data.login_via || '').toLowerCase();
                        const loginVia = (isRadiusConfigured && rawLoginVia === 'local') ? 'radius' : (data.login_via || 'Null');
                        const remoteIp = data.remote_ip || 'Null';
                        const mac = data.active_caller_id || 'Null';
                        // Bug lama: "0 || 'Null'" bikin kecepatan 0 Mbps tertampil sebagai "Null".
                        // Down/Up di sini masih nilai dari cache (rata-rata per siklus cron);
                        // akan ditimpa oleh angka live dari refreshLiveTraffic() di bawah begitu selesai.
                        const download = (data.download !== undefined && data.download !== null) ? data.download : 'Null';
                        const upload = (data.upload !== undefined && data.upload !== null) ? data.upload : 'Null';
                        const kuota = data.kuota || 'N/A';
                        const uptime = data.uptime || 'N/A';
                        const linkUp = data.last_link_up || 'N/A';
                        const linkDown = data.last_link_down || 'N/A';
                        const pemakaian = data.pemakaian || 'N/A';

                        // Update status badge (Table)
                        if (statusElement2) {
                            statusElement2.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        }

                        // Update status badge (Modal)
                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) {
                            modalStatusEl.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        }

                        // Update real-time info (Table)
                        if (realtimeElement) {
                            realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, false)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        // Update real-time info (Modal)
                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) {
                            modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-modal-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-modal-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, true)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        // Tambahkan tombol remote ONT
                        try {
                            addRemoteButton(idPel, ipServer, userServer, passwordServer, remoteIp);
                        } catch (btnErr) {
                            console.warn(`Remote button error: ${btnErr}`);
                        }

                        // Down/Up di atas masih dari cache (rata-rata per siklus cron). Ambil
                        // angka live (monitor-traffic instan) secara terpisah TANPA menunda
                        // tampilnya status/badge yang sudah dirender di atas.
                        refreshLiveTraffic(idPel, ipServer, userServer, passwordServer);
                    } catch (onlineErr) {
                        console.error(`Online status error: ${onlineErr}`);
                    }

                } else {
                    try {
                        const lastDisconnect = data.ceklastdisconnect || 'Null';
                        const kuota = data.kuota || 'N/A';
                        const uptime = data.uptime || 'N/A';
                        const linkUp = data.last_link_up || 'N/A';
                        const linkDown = data.last_link_down || 'N/A';
                        const pemakaian = data.pemakaian || 'N/A';

                        // Update status badge (Table)
                        if (statusElement2) {
                            statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        }

                        // Update status badge (Modal)
                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) {
                            modalStatusEl.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        }

                        // Update real-time info (Table)
                        if (realtimeElement) {
                            realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        // Update real-time info (Modal)
                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) {
                            modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        // Hapus tombol remote jika ada
                        try {
                            const container = document.getElementById(`remoteContainer-${idPel}`);
                            if (container) container.innerHTML = '';
                            const modalContainer = document.getElementById(`remoteContainerModal-${idPel}`);
                            if (modalContainer) modalContainer.innerHTML = '';
                        } catch (e) {
                            console.warn(`Container clear error: ${e}`);
                        }

                        // Catatan: dulu di sini juga panggil cekRadwhoFallback() -- sudah
                        // dihapus krn bisa menimpa grid detail yang baru saja dirender di
                        // atas dgn dump mentah command radwho ("kayak time server"). Data
                        // pelanggan RADIUS sekarang sudah masuk lewat data.* di atas
                        // (fallback radacct di get_cached_pppoe_status.php).
                    } catch (offlineErr) {
                        console.error(`Offline status error: ${offlineErr}`);
                    }
                }
            } catch (processErr) {
                console.error(`Data processing error for ${idPel}:`, processErr);
                renderFetchErrorUI(idPel, 'Data processing error');
            }
        })
        .catch(error => {
            clearTimeout(fetchTimeoutId);
            console.error('Fetch error:', error);
            // Pelanggan RADIUS MODE/MULTI MODE: gagal/timeout konek Mikrotik API itu wajar
            // (server RADIUS-only tidak selalu punya API aktif) -- tampilkan sbg OFFLINE,
            // sama seperti LOCAL/API, bukan badge "Error"/teks timeout yang menakutkan.
            const isRadiusConfiguredMode = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
            if (isRadiusConfiguredMode) {
                renderOfflineRadiusUI(idPel);
            } else {
                renderFetchErrorUI(idPel, error && error.name === 'AbortError' ? 'Timeout - server tidak merespon' : 'Fetch Error');
            }
        });
    } catch (outerErr) {
        console.error('FetchData outer error:', outerErr);
        return Promise.resolve();
    }
}

// Antrian sekuensial: status di-load satu per satu berurutan dari baris paling atas,
// bukan langsung bersamaan untuk semua baris. Sebelumnya tiap baris fetch sendiri2
// (dengan jitter kecil) sehingga puluhan request menghantam router/server bareng-bareng
// dan berujung timeout ("TIMEOUT - SERVER TIDAK MERESPON") di banyak baris sekaligus.
window.__customerFetchOrder = window.__customerFetchOrder || [];
window.__customerFetchQueueStarted = window.__customerFetchQueueStarted || false;
// Baris yang lagi kelihatan di layar (viewport) -- hanya baris ini yang di-fetch.
// Baris yang di-scroll keluar layar dilewati dulu supaya tidak buang request percuma
// untuk data yang tidak sedang dilihat user.
window.__customerVisibleRows = window.__customerVisibleRows || new Set();
window.__customerRowObserver = window.__customerRowObserver || null;

const CUSTOMER_FETCH_GAP_MS = 350;          // jeda antar baris supaya router/server tidak dibanjiri request
const CUSTOMER_FETCH_CYCLE_DELAY_MS = 8000; // jeda sebelum mulai putaran refresh berikutnya dari baris paling atas
const CUSTOMER_FETCH_IDLE_RETRY_MS = 500;   // saat tidak ada baris visible sama sekali, cek lagi lebih cepat

function customerFetchDelay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Modal kecil (Manual Active, Setting Diskon, Tambah Biaya, dst - semua pakai
// class .qts-modal-overlay) berisi <select> native yang dipaksa browser tertutup
// sendiri kalau ada reflow/DOM update besar terjadi saat dropdown-nya lagi
// terbuka (mis. chart traffic di-refresh, badge status di-update). Makanya
// antrian fetch di bawah ini DIJEDA total selama salah satu modal ini terbuka,
// supaya tidak ada update DOM yang bisa menutup paksa dropdown select-nya.
function isSecondaryQtsModalOpen() {
    return Array.prototype.some.call(
        document.querySelectorAll('.qts-modal-overlay'),
        function(el) { return el.style.display === 'flex'; }
    );
}

function getCustomerRowObserver() {
    if (!window.__customerRowObserver) {
        window.__customerRowObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                const idPel = entry.target.getAttribute('data-fetch-idpel');
                if (!idPel) return;
                if (entry.isIntersecting) {
                    window.__customerVisibleRows.add(idPel);
                } else {
                    window.__customerVisibleRows.delete(idPel);
                }
            });
        }, { root: null, rootMargin: '400px 0px', threshold: 0 });
    }
    return window.__customerRowObserver;
}

async function runCustomerFetchQueue() {
    while (true) {
        // Salin urutan saat ini: baris yang baru muncul (lazy-load) akan otomatis
        // ikut ke putaran berikutnya karena order di-push langsung ke array asli.
        const order = window.__customerFetchOrder;
        let didFetch = false;
        for (let i = 0; i < order.length; i++) {
            const idPel = order[i];
            const p = window.__customerFetchParams[idPel];
            if (!p) continue;
            if (!window.__customerVisibleRows.has(idPel)) {
                continue; // baris ini lagi di luar layar, skip dulu
            }
            while (isSecondaryQtsModalOpen()) {
                await customerFetchDelay(300); // tunggu modal Manual Active/Setting Diskon/dll ditutup dulu
            }
            didFetch = true;
            try {
                await fetchData(idPel, p.ip, p.us, p.ps, p.mode);
            } catch (e) {
                console.warn(`Queue fetch error for ${idPel}:`, e);
            }
            await customerFetchDelay(CUSTOMER_FETCH_GAP_MS);
        }
        // Kalau lap ini tidak ada baris visible yang di-fetch (mis. baru load & observer
        // belum sempat trigger, atau user sedang scroll ke bagian tanpa baris), coba lagi
        // cepat supaya baris yang baru masuk layar tidak nunggu kelamaan.
        await customerFetchDelay(didFetch ? CUSTOMER_FETCH_CYCLE_DELAY_MS : CUSTOMER_FETCH_IDLE_RETRY_MS);
    }
}

function startFetching(idPel, ipServer, userServer, passwordServer, customerMode) {
    // Simpan supaya tombol reload manual (saat error) tahu parameter apa yang dipakai ulang.
    // customerMode = MODE pelanggan tersimpan di DB (RADIUS MODE/API MODE/MULTI MODE),
    // dipakai sebagai sumber kebenaran tambahan saat flag "radius" live dari Mikrotik
    // salah/basi (mis. ada secret lokal sisa untuk pelanggan yang sebenarnya RADIUS MODE).
    // Params ini TETAP disimpan meski Mode Cepat aktif - dipakai untuk fetch on-demand
    // saat modal Overview pelanggan ini dibuka (lihat listener shown.bs.modal).
    window.__customerFetchParams[idPel] = { ip: ipServer, us: userServer, ps: passwordServer, mode: customerMode || '' };

    // Untuk row yang baru di-load, isi badge SLA langsung dari cache jika sudah ada.
    renderCustomerSlaBadge(idPel);

    // Mode Cepat: JANGAN antrikan status untuk di-cek terus-menerus di tabel - baru
    // dicek saat operator benar-benar buka Overview pelanggan ybs (hemat beban ke
    // Mikrotik kalau daftar pelanggan yang tampil banyak). Kolom Status cukup dikasih
    // placeholder statis, bukan "Loading..." yang menyiratkan proses sedang berjalan.
    if (window.__fastStatusMode) {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) {
            statusEl.innerHTML = '<span class="badge badge-sm bg-gradient-secondary">Buka Overview utk cek status</span>';
        }
        return;
    }

    // Masukkan ke antrian sesuai urutan tampil di tabel (top -> bottom) kalau belum ada.
    if (!window.__customerFetchOrder.includes(idPel)) {
        window.__customerFetchOrder.push(idPel);
    }

    // Amati baris tabelnya: status baru mulai di-fetch begitu barisnya kelihatan di layar.
    const rowEl = document.getElementById(`customerRow-${idPel}`);
    if (rowEl) {
        rowEl.setAttribute('data-fetch-idpel', idPel);
        getCustomerRowObserver().observe(rowEl);
    } else {
        // Fallback kalau row belum ketemu di DOM: anggap visible supaya tetap ke-load.
        window.__customerVisibleRows.add(idPel);
    }

    // Antrian cuma boleh jalan satu kali (loop tak berhenti), jangan start dobel.
    if (!window.__customerFetchQueueStarted) {
        window.__customerFetchQueueStarted = true;
        runCustomerFetchQueue();
    }
}

// Dipanggil saat modal Overview pelanggan dibuka. Kalau Mode Cepat aktif, status
// belum pernah di-fetch sama sekali untuk pelanggan ini (lihat startFetching()) -
// jadi baru di-fetch DI SINI, on-demand, satu pelanggan saja (bukan semua baris).
function fetchStatusOnDemandIfFastMode(idPel) {
    if (!window.__fastStatusMode) return;
    const p = window.__customerFetchParams[idPel];
    if (!p) return;
    fetchData(idPel, p.ip, p.us, p.ps, p.mode).catch(function(e) {
        console.warn(`On-demand fetch error for ${idPel}:`, e);
    });
}

function acsHtmlEscape(str) {
    return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// GenieACS mengembalikan tiap parameter sebagai beberapa key rata (flattened):
// "...Path._value", "...Path._object", "...Path._writable", "...Path._timestamp", "...Path._type".
// Hanya "_value" (atau key polos tanpa sufiks ini) yang relevan untuk ditampilkan.
function acsCleanParams(rawParams) {
    const cleaned = {};
    Object.keys(rawParams || {}).forEach(function(key) {
        if (/\.(_object|_writable|_timestamp|_type|_attributes)$/.test(key)) {
            return;
        }
        const cleanKey = key.replace(/\._value$/, '');
        cleaned[cleanKey] = rawParams[key];
    });
    return cleaned;
}

async function acsPostJson(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        credentials: 'same-origin',
        body: new URLSearchParams(payload)
    });
    const text = await res.text();
    if (!text || !text.trim()) {
        throw new Error('Fitur ini belum tersedia di server (endpoint kosong). Hubungi admin untuk mengaktifkan kembali.');
    }
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        throw new Error('Respons server tidak valid.');
    }
    if (!data || data.success === false) {
        throw new Error((data && (data.error || data.message)) || 'Gagal memproses permintaan.');
    }
    return data;
}

function formatAcsSyncBadge(cacheAge, cacheExpired) {
    if (cacheAge === null || cacheAge === undefined) {
        return { label: 'SYNC: -', cls: 'acs-sync-badge-stale' };
    }
    const mins = Math.max(0, Math.floor(cacheAge / 60));
    const label = 'SYNC: ' + new Date(Date.now() - cacheAge * 1000).toLocaleString('id-ID') + ' (' + mins + ' mnt)';
    const cls = cacheExpired ? 'acs-sync-badge-expired' : (mins > 5 ? 'acs-sync-badge-stale' : 'acs-sync-badge-fresh');
    return { label: label, cls: cls };
}

function loadSlaHistory(idpel) {
    const body = document.getElementById('slaHistoryBody-' + idpel);
    if (!body) return;
    fetch('getdata/get_customer_sla_history.php?idpel=' + encodeURIComponent(idpel))
        .then(function(res) { return res.json().catch(function() { return null; }); })
        .then(function(data) {
            if (!data || !data.success) {
                body.innerHTML = '<span class="text-secondary small">' + acsHtmlEscape((data && data.message) || 'Gagal memuat riwayat SLA.') + '</span>';
                return;
            }
            const rows = Array.isArray(data.rows) ? data.rows : [];
            if (rows.length === 0) {
                body.innerHTML = '<span class="text-secondary small">Belum ada riwayat SLA bulanan untuk pelanggan ini.</span>';
                return;
            }

            const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            let html = '<table class="sla-history-table"><thead><tr><th>Bulan</th><th>SLA</th></tr></thead><tbody>';
            rows.forEach(function(row) {
                const parts = String(row.month || '').split('-');
                const year = parts[0] || '';
                const monthIdx = parseInt(parts[1], 10) - 1;
                const monthLabel = (monthIdx >= 0 && monthIdx < 12 ? monthNames[monthIdx] : row.month) + ' ' + year;
                const percent = Number(row.sla_percent || 0);
                const badgeClass = getCustomerSlaBadgeClass(percent);
                html += '<tr><td>' + acsHtmlEscape(monthLabel) + '</td><td><span class="' + badgeClass + '">' + percent.toFixed(2) + '%</span></td></tr>';
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(function(err) {
            console.error('SLA history load error:', err);
            body.innerHTML = '<span class="text-secondary small">Gagal memuat riwayat SLA.</span>';
        });
}

function loadAcsDevicePanel(idpel) {
    const body = document.getElementById('acsPanelBody-' + idpel);
    if (!body) return;
    fetch('getdata/acs_cache_data.php?idpel=' + encodeURIComponent(idpel))
        .then(function(res) { return res.json().catch(function() { return null; }); })
        .then(function(data) { renderAcsDevicePanel(idpel, data); })
        .catch(function(err) {
            console.error('ACS panel load error:', err);
            body.innerHTML = '<span class="acs-empty-text">Gagal memuat data ACS.</span>';
        });
}

function renderAcsDevicePanel(idpel, data) {
    const body = document.getElementById('acsPanelBody-' + idpel);
    const syncInfoEl = document.getElementById('acs-sync-info-' + idpel);
    const panelEl = document.getElementById('acsPanel-' + idpel);
    if (!body) return;

    if (syncInfoEl) {
        const sync = formatAcsSyncBadge(data ? data.cache_age : null, data ? data.cache_expired : true);
        syncInfoEl.textContent = sync.label;
        syncInfoEl.className = 'rounded px-2 py-1 ' + sync.cls;
    }

    if (!data || data.error) {
        body.innerHTML = '<span class="acs-empty-text">' + acsHtmlEscape((data && data.error) || 'Data ACS tidak tersedia.') + '</span>';
        return;
    }

    const devices = Array.isArray(data.devices) ? data.devices : [];
    if (devices.length === 0) {
        body.innerHTML = '<span class="acs-empty-text">Perangkat ACS untuk pelanggan ini belum ditemukan di cache.</span>';
        return;
    }

    const device = devices[0];
    if (panelEl) {
        panelEl.dataset.serverId = device.server_id || '';
        panelEl.dataset.serial = device.serial_raw || device.serial || '';
    }

    let html = '<div class="acs-device-info-grid">';
    const isOnline = String(device.status || '').toUpperCase() === 'ONLINE';
    const infoItems = [
        ['Serial', acsHtmlEscape(device.serial || '-')],
        ['Brand', acsHtmlEscape(device.manufacturer || '-')],
        ['PPPoE Username', acsHtmlEscape(device.pppoe_username || '-')],
        ['PPPoE Username 2', acsHtmlEscape(device.pppoe_username2 || '-')],
        ['PPPoE IP', acsHtmlEscape(device.pppoe_ip || '-')],
        ['RX redaman', acsHtmlEscape(device.rx_power || '-')],
        ['TX', acsHtmlEscape(device.tx_power || '-')],
        ['Status', '<span class="badge ' + (isOnline ? 'bg-success' : 'bg-secondary') + '">' + acsHtmlEscape(device.status || 'UNKNOWN') + '</span>'],
        ['Server', acsHtmlEscape(device.server_name || '-')],
        ['Last Inform', acsHtmlEscape(device.last_inform || '-')]
    ];
    infoItems.forEach(function(item) {
        html += '<div class="acs-device-info-item"><span class="acs-device-info-label">' + item[0] + '</span><span class="acs-device-info-value">' + item[1] + '</span></div>';
    });
    html += '</div>';

    html += '<div class="acs-ssid-section"><div class="acs-ssid-title">SSID Info</div><div class="acs-ssid-grid">';
    for (let i = 1; i <= 4; i++) {
        const ssidName = device['ssid_' + i] || '';
        const enabledRaw = String(device['ssid_enable_' + i] || '').toLowerCase();
        const isOn = enabledRaw === '1' || enabledRaw === 'true' || enabledRaw === 'enabled';
        const ssidParamKey = device['ssid_param_' + i] || '';
        const passParamKey = device['ssid_pass_param_' + i] || '';
        const enableParamKey = device['ssid_enable_param_' + i] || '';
        const ssidPass = device['ssid_pass_' + i] || '';

        html += '<div class="acs-ssid-row">';
        html += '<div class="acs-ssid-card-header"><span class="acs-ssid-name">SSID ' + i + ' <span class="badge ' + (isOn ? 'bg-success' : 'bg-secondary') + '">' + (isOn ? 'ON' : 'OFF') + '</span></span>';
        html += '<button type="button" class="btn btn-sm btn-outline-primary" data-idpel="' + acsHtmlEscape(idpel) + '" data-ssid-index="' + i
            + '" data-ssid-name="' + acsHtmlEscape(ssidName) + '" data-ssid-pass="' + acsHtmlEscape(ssidPass) + '" data-ssid-on="' + (isOn ? '1' : '0')
            + '" data-ssid-param="' + acsHtmlEscape(ssidParamKey) + '" data-ssid-pass-param="' + acsHtmlEscape(passParamKey) + '" data-ssid-enable-param="' + acsHtmlEscape(enableParamKey)
            + '" onclick="openSsidEditor(this)">Edit</button>';
        html += '</div>';
        html += '<div class="acs-ssid-value">' + acsHtmlEscape(ssidName || '-') + '</div>';
        html += '<div class="acs-ssid-value">Password: ' + (ssidPass ? '******' : '-') + '</div>';
        html += '</div>';
    }
    html += '</div>';
    html += '<div class="acs-device-action-row"><button type="button" class="btn btn-sm btn-danger" onclick="rebootAcsDevice(\'' + idpel.replace(/'/g, "\\'") + '\')"><i class="fas fa-power-off"></i> Restart / Reboot Perangkat</button></div>';
    html += '</div>';

    const params = acsCleanParams(device.all_params || {});

    // Kelompokkan parameter per WANConnectionDevice, lalu deteksi tipe koneksi
    // (PPPoE / DHCP / Static IP) + status + IP WAN dari sub-object PPP/IP connection-nya.
    const wanKeys = {};
    Object.keys(params).forEach(function(key) {
        const match = key.match(/WANConnectionDevice\.(\d+)/);
        if (match) {
            const wanId = 'WANConnectionDevice.' + match[1];
            if (!wanKeys[wanId]) wanKeys[wanId] = {};
            wanKeys[wanId][key] = params[key];
        }
    });

    function acsFindParam(paramSet, suffixes) {
        const keys = Object.keys(paramSet);
        for (let s = 0; s < suffixes.length; s++) {
            for (let k = 0; k < keys.length; k++) {
                if (keys[k].indexOf(suffixes[s]) !== -1) {
                    return { key: keys[k], value: paramSet[keys[k]] };
                }
            }
        }
        return null;
    }

    function acsDetectWanSummary(wanParams) {
        const hasPpp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANPPPConnection') !== -1; });
        const hasIp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANIPConnection') !== -1; });

        let serviceType = 'Tidak diketahui';
        if (hasPpp) {
            serviceType = 'PPPoE';
        } else if (hasIp) {
            const addressing = acsFindParam(wanParams, ['.AddressingType']);
            const addressingVal = addressing ? String(addressing.value).toLowerCase() : '';
            serviceType = addressingVal.indexOf('static') !== -1 ? 'IP Static' : 'DHCP';
        }

        const ipParam = acsFindParam(wanParams, ['.ExternalIPAddress']);
        const statusParam = acsFindParam(wanParams, ['.ConnectionStatus']);
        const usernameParam = hasPpp ? acsFindParam(wanParams, ['.Username']) : null;

        return {
            serviceType: serviceType,
            wanIp: ipParam ? ipParam.value : '-',
            status: statusParam ? statusParam.value : '-',
            username: usernameParam ? usernameParam.value : ''
        };
    }

    html += '<div class="acs-wan-section">';
    html += '<div class="acs-wan-header"><div class="acs-wan-title">WAN Info</div><button type="button" class="btn btn-sm btn-outline-success" onclick="openWanAddDialog(\'' + idpel.replace(/'/g, "\\'") + '\')">Add</button></div>';
    const wanIds = Object.keys(wanKeys);
    if (wanIds.length === 0) {
        html += '<span class="acs-wan-empty">Belum ada data WAN connection di cache.</span>';
    } else {
        html += '<div class="acs-wan-grid">';
        wanIds.forEach(function(wanId) {
            const wanParams = wanKeys[wanId];
            const summary = acsDetectWanSummary(wanParams);
            const encodedParams = acsHtmlEscape(JSON.stringify(wanParams));
            const isConnected = String(summary.status).toLowerCase().indexOf('connected') !== -1 && String(summary.status).toLowerCase().indexOf('disconnected') === -1;

            html += '<div class="acs-wan-card">';
            html += '<div class="acs-wan-card-header"><span class="acs-wan-card-title">' + acsHtmlEscape(wanId) + '</span>';
            html += '<button type="button" class="btn btn-sm btn-outline-primary" data-idpel="' + acsHtmlEscape(idpel) + '" data-wan-id="' + acsHtmlEscape(wanId)
                + '" data-wan-params="' + encodedParams + '" onclick="openWanEditor(this)">Edit</button></div>';

            html += '<div class="acs-wan-summary-grid">';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Tipe Layanan</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.serviceType) + '</span></div>';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Status</span><span class="acs-wan-param-value"><span class="badge ' + (isConnected ? 'bg-success' : 'bg-secondary') + '">' + acsHtmlEscape(summary.status) + '</span></span></div>';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">IP WAN</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.wanIp) + '</span></div>';
            if (summary.username) {
                html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Username</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.username) + '</span></div>';
            }
            html += '</div>';

            const paramEntries = Object.keys(wanParams).slice(0, 6);
            if (paramEntries.length > 0) {
                html += '<div class="acs-wan-param-list mt-2">';
                paramEntries.forEach(function(pKey) {
                    const shortKey = pKey.split('.').slice(-1)[0];
                    html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">' + acsHtmlEscape(shortKey) + '</span><span class="acs-wan-param-value">' + acsHtmlEscape(wanParams[pKey]) + '</span></div>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
    }
    html += '</div>';

    // Local Terhubung: perangkat lokal (Wi-Fi/LAN) yang terkoneksi ke ONT/router pelanggan.
    const localHosts = {};
    Object.keys(params).forEach(function(key) {
        const match = key.match(/Hosts\.Host\.(\d+)\.(HostName|IPAddress|InterfaceType)$/);
        if (!match) return;
        const hostNum = match[1];
        const attr = match[2];
        if (!localHosts[hostNum]) localHosts[hostNum] = {};
        localHosts[hostNum][attr] = params[key];
    });
    const hostKeys = Object.keys(localHosts).sort(function(a, b) { return parseInt(a, 10) - parseInt(b, 10); });

    html += '<div class="acs-local-hosts-wrap">';
    html += '<div class="acs-local-hosts-title">Local Terhubung (' + hostKeys.length + ')</div>';
    if (hostKeys.length === 0) {
        html += '<span class="acs-empty-text">Tidak ada data perangkat lokal terhubung di cache.</span>';
    } else {
        html += '<div class="acs-local-hosts-grid">';
        hostKeys.forEach(function(hostNum) {
            const host = localHosts[hostNum] || {};
            html += '<div class="acs-local-host-card">';
            html += '<div class="acs-local-host-name">Device ' + acsHtmlEscape(hostNum) + '</div>';
            if (host.HostName) html += '<div class="acs-local-host-item"><strong>Host:</strong> ' + acsHtmlEscape(host.HostName) + '</div>';
            if (host.IPAddress) html += '<div class="acs-local-host-item"><strong>IP:</strong> ' + acsHtmlEscape(host.IPAddress) + '</div>';
            if (host.InterfaceType) html += '<div class="acs-local-host-item"><strong>Interface:</strong> ' + acsHtmlEscape(host.InterfaceType) + '</div>';
            html += '</div>';
        });
        html += '</div>';
    }
    html += '</div>';

    // Toggle data mentah ACS (semua parameter), default tersembunyi.
    const rawParamKeys = Object.keys(params);
    html += '<div class="acs-raw-toggle-wrap">';
    html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAcsRawData(\'' + idpel.replace(/'/g, "\\'") + '\')" id="acsRawToggleBtn-' + acsHtmlEscape(idpel) + '">Tampilkan Semua Data ACS (' + rawParamKeys.length + ')</button>';
    html += '<div class="acs-raw-params-list d-none" id="acsRawParamsList-' + acsHtmlEscape(idpel) + '">';
    rawParamKeys.sort().forEach(function(pKey) {
        html += '<div class="acs-raw-param-row"><span class="acs-param-key">' + acsHtmlEscape(pKey) + '</span><span class="acs-param-value">' + acsHtmlEscape(params[pKey]) + '</span></div>';
    });
    html += '</div>';
    html += '</div>';

    body.innerHTML = html;
}

function toggleAcsRawData(idpel) {
    const list = document.getElementById('acsRawParamsList-' + idpel);
    const btn = document.getElementById('acsRawToggleBtn-' + idpel);
    if (!list) return;
    const isHidden = list.classList.contains('d-none');
    if (isHidden) {
        list.classList.remove('d-none');
        if (btn) btn.textContent = btn.textContent.replace('Tampilkan', 'Sembunyikan');
    } else {
        list.classList.add('d-none');
        if (btn) btn.textContent = btn.textContent.replace('Sembunyikan', 'Tampilkan');
    }
}

function acsCurrentPanelIds(idpel) {
    const panelEl = document.getElementById('acsPanel-' + idpel);
    return {
        serverId: panelEl ? (panelEl.dataset.serverId || '') : '',
        serial: panelEl ? (panelEl.dataset.serial || '') : ''
    };
}

function openSsidEditor(button) {
    const idpel = button.getAttribute('data-idpel') || '';
    const ids = acsCurrentPanelIds(idpel);
    document.getElementById('acsSsidIdpel').value = idpel;
    document.getElementById('acsSsidServerId').value = ids.serverId;
    document.getElementById('acsSsidSerial').value = ids.serial;
    document.getElementById('acsSsidParam').value = button.getAttribute('data-ssid-param') || '';
    document.getElementById('acsSsidPassParam').value = button.getAttribute('data-ssid-pass-param') || '';
    document.getElementById('acsSsidEnableParam').value = button.getAttribute('data-ssid-enable-param') || '';
    document.getElementById('acsSsidName').value = button.getAttribute('data-ssid-name') || '';
    document.getElementById('acsSsidPassword').value = '';
    document.getElementById('acsSsidEnable').checked = button.getAttribute('data-ssid-on') === '1';
    document.getElementById('acsSsidEditorMsg').innerHTML = '';
    document.getElementById('acsSsidEditorModal').style.display = 'flex';
}

function closeSsidEditor() {
    document.getElementById('acsSsidEditorModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const ssidForm = document.getElementById('acsSsidEditorForm');
    if (ssidForm) {
        ssidForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msgEl = document.getElementById('acsSsidEditorMsg');
            msgEl.innerHTML = '<span class="text-secondary">Menyimpan...</span>';
            const idpel = document.getElementById('acsSsidIdpel').value;
            const payload = {
                idpel: idpel,
                server_id: document.getElementById('acsSsidServerId').value,
                serial: document.getElementById('acsSsidSerial').value,
                ssid_param: document.getElementById('acsSsidParam').value,
                ssid_pass_param: document.getElementById('acsSsidPassParam').value,
                ssid_enable_param: document.getElementById('acsSsidEnableParam').value,
                ssid: document.getElementById('acsSsidName').value,
                ssid_password: document.getElementById('acsSsidPassword').value,
                ssid_enable: document.getElementById('acsSsidEnable').checked ? '1' : '0'
            };
            acsPostJson('getdata/acs_update_ssid.php', payload)
                .then(function() {
                    msgEl.innerHTML = '<span class="text-success">Berhasil disimpan.</span>';
                    setTimeout(function() {
                        closeSsidEditor();
                        loadAcsDevicePanel(idpel);
                    }, 700);
                })
                .catch(function(err) {
                    msgEl.innerHTML = '<span class="text-danger">' + acsHtmlEscape(err.message || 'Gagal menyimpan.') + '</span>';
                });
        });
    }

    const wanForm = document.getElementById('acsWanEditorForm');
    if (wanForm) {
        wanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msgEl = document.getElementById('acsWanEditorMsg');
            msgEl.innerHTML = '<span class="text-secondary">Menyimpan...</span>';
            const idpel = document.getElementById('acsWanEditIdpel').value;
            const payload = {
                idpel: idpel,
                server_id: document.getElementById('acsWanEditServerId').value,
                serial: document.getElementById('acsWanEditSerial').value,
                wan_id: document.getElementById('acsWanEditWanId').value
            };
            document.querySelectorAll('#acsWanEditorFields [data-param-key]').forEach(function(input) {
                payload[input.getAttribute('data-param-key')] = input.value;
            });
            acsPostJson('getdata/acs_update_wan_parameters.php', payload)
                .then(function() {
                    msgEl.innerHTML = '<span class="text-success">Berhasil disimpan.</span>';
                    setTimeout(function() {
                        closeWanEditor();
                        loadAcsDevicePanel(idpel);
                    }, 700);
                })
                .catch(function(err) {
                    msgEl.innerHTML = '<span class="text-danger">' + acsHtmlEscape(err.message || 'Gagal menyimpan.') + '</span>';
                });
        });
    }

    const wanAddForm = document.getElementById('acsWanAddForm');
    if (wanAddForm) {
        wanAddForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msgEl = document.getElementById('acsWanAddMsg');
            msgEl.innerHTML = '<span class="text-secondary">Memproses...</span>';
            const idpel = document.getElementById('acsWanAddIdpel').value;
            const formData = new FormData(wanAddForm);
            const payload = {
                idpel: idpel,
                server_id: document.getElementById('acsWanAddServerId').value,
                serial: document.getElementById('acsWanAddSerial').value,
                connection_type: formData.get('connection_type') || '',
                username: formData.get('username') || '',
                password: formData.get('password') || '',
                vlan_id: formData.get('vlan_id') || ''
            };
            acsPostJson('getdata/acs_add_wan_connection_device.php', payload)
                .then(function() {
                    msgEl.innerHTML = '<span class="text-success">Berhasil ditambahkan.</span>';
                    setTimeout(function() {
                        closeWanAddDialog();
                        loadAcsDevicePanel(idpel);
                    }, 700);
                })
                .catch(function(err) {
                    msgEl.innerHTML = '<span class="text-danger">' + acsHtmlEscape(err.message || 'Gagal menambahkan.') + '</span>';
                });
        });
    }
});

function rebootAcsDevice(idpel) {
    if (!confirm('Reboot perangkat pelanggan ' + idpel + '? Koneksi internet pelanggan akan terputus sementara.')) {
        return;
    }
    const ids = acsCurrentPanelIds(idpel);
    acsPostJson('getdata/acs_reboot_device.php', { idpel: idpel, server_id: ids.serverId, serial: ids.serial })
        .then(function() {
            alert('Perintah reboot berhasil dikirim.');
        })
        .catch(function(err) {
            alert(err.message || 'Gagal mengirim perintah reboot.');
        });
}

function openWanEditor(button) {
    const idpel = button.getAttribute('data-idpel') || '';
    const wanId = button.getAttribute('data-wan-id') || '';
    let wanParams = {};
    try {
        wanParams = JSON.parse(button.getAttribute('data-wan-params') || '{}');
    } catch (e) {
        wanParams = {};
    }
    const ids = acsCurrentPanelIds(idpel);
    document.getElementById('acsWanEditIdpel').value = idpel;
    document.getElementById('acsWanEditServerId').value = ids.serverId;
    document.getElementById('acsWanEditSerial').value = ids.serial;
    document.getElementById('acsWanEditWanId').value = wanId;

    const fieldsWrap = document.getElementById('acsWanEditorFields');
    let fieldsHtml = '';
    Object.keys(wanParams).forEach(function(pKey) {
        const shortKey = pKey.split('.').slice(-1)[0];
        fieldsHtml += '<div class="acs-wan-form-field"><label class="form-label">' + acsHtmlEscape(shortKey) + '</label>'
            + '<input type="text" class="form-control" data-param-key="' + acsHtmlEscape(pKey) + '" value="' + acsHtmlEscape(wanParams[pKey]) + '"></div>';
    });
    fieldsWrap.innerHTML = fieldsHtml || '<span class="acs-wan-empty">Tidak ada parameter untuk diedit.</span>';
    document.getElementById('acsWanEditorMsg').innerHTML = '';
    document.getElementById('acsWanEditorModal').style.display = 'flex';
}

function closeWanEditor() {
    document.getElementById('acsWanEditorModal').style.display = 'none';
}

function openWanAddDialog(idpel) {
    const ids = acsCurrentPanelIds(idpel);
    document.getElementById('acsWanAddIdpel').value = idpel;
    document.getElementById('acsWanAddServerId').value = ids.serverId;
    document.getElementById('acsWanAddSerial').value = ids.serial;
    document.getElementById('acsWanAddForm').reset();
    document.getElementById('acsWanAddMsg').innerHTML = '';
    document.getElementById('acsWanAddModal').style.display = 'flex';
}

function closeWanAddDialog() {
    document.getElementById('acsWanAddModal').style.display = 'none';
}

function getCustomerSlaBadgeClass(percent) {
    if (percent >= 99.5) {
        return 'badge badge-sm bg-gradient-success';
    }
    if (percent >= 95) {
        return 'badge badge-sm bg-gradient-warning';
    }
    if (percent <= 0) {
        return 'badge badge-sm bg-gradient-info';
    }
    return 'badge badge-sm bg-gradient-danger';
}

function renderCustomerSlaBadge(idPel) {
    const targets = [
        document.getElementById(`data-sla-${idPel}`),
        document.getElementById(`data-sla-modal-${idPel}`)
    ].filter(Boolean);
    if (targets.length === 0) return;

    const payload = window.customerSlaSummary && window.customerSlaSummary.customers
        ? window.customerSlaSummary.customers[idPel]
        : null;

    if (!payload) {
        targets.forEach(target => {
            target.innerHTML = '<span class="badge badge-sm bg-gradient-info">SLA PELANGGAN 0.00%</span>';
        });
        return;
    }

    const percent = Number(payload.sla_percent || 0);
    targets.forEach(target => {
        target.innerHTML = `<span class="${getCustomerSlaBadgeClass(percent)}">SLA PELANGGAN ${percent.toFixed(2)}%</span>`;
    });
}

function renderAllCustomerSlaBadges() {
    const payload = window.customerSlaSummary && window.customerSlaSummary.customers
        ? window.customerSlaSummary.customers
        : {};

    Object.keys(payload).forEach(renderCustomerSlaBadge);
}

function renderSlaBadgesInContainer(container) {
    if (!container) return;
    const targets = container.querySelectorAll('[id^="data-sla-"]');
    targets.forEach(function(target) {
        const idPel = String(target.id || '').replace('data-sla-', '');
        if (idPel) {
            renderCustomerSlaBadge(idPel);
        }
    });
}

function loadCustomerSlaSummary() {
    if (window.customerSlaSummaryLoading) {
        return window.customerSlaSummaryPromise || Promise.resolve(null);
    }
    // Sama seperti loadTiket()/runCustomerFetchQueue() - hindari update badge SLA
    // massal (renderAllCustomerSlaBadges menyentuh SEMUA baris) selama modal kecil
    // (Manual Active dkk) terbuka, supaya tidak menutup paksa <select> yang lagi dibuka.
    if (typeof isSecondaryQtsModalOpen === 'function' && isSecondaryQtsModalOpen()) {
        return Promise.resolve(null);
    }

    window.customerSlaSummaryLoading = true;
    window.customerSlaSummaryPromise = fetch('getdata/get_customer_sla.php?_=' + Date.now(), {
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(payload => {
            if (!payload || payload.success === false) {
                throw new Error(payload && payload.message ? payload.message : 'Gagal memuat SLA pelanggan');
            }

            window.customerSlaSummary = payload;
            renderAllCustomerSlaBadges();
            return payload;
        })
        .catch(error => {
            console.error('Customer SLA summary error:', error);
            window.customerSlaSummary = { customers: {}, odps: {} };
            return null;
        })
        .finally(() => {
            window.customerSlaSummaryLoading = false;
        });

    return window.customerSlaSummaryPromise;
}

document.addEventListener('DOMContentLoaded', function() {
    loadCustomerSlaSummary().then(function() {
        renderSlaBadgesInContainer(document);
    });
    setInterval(loadCustomerSlaSummary, 30 * 60 * 1000);
});



</script>


<script>
function openMonitorModal(element, idPel, ip, user, password) {
    try {
        console.log(`[openMonitorModal] Opening modal for ${idPel}`);
        // Modal akan dibuka via Bootstrap data-bs-toggle="modal"
        // Function ini hanya untuk initialization jika diperlukan nanti
        return true;
    } catch(error) {
        console.error(`❌ Error in openMonitorModal:`, error);
        return false;
    }
}

function addRemoteButton(idPel, ipServer, userServer, passwordServer, remoteIp) {
    const containers = [
        document.getElementById(`remoteContainer-${idPel}`),
        document.getElementById(`remoteContainerModal-${idPel}`)
    ].filter(Boolean);

    if (containers.length === 0) return;

    containers.forEach((container, index) => {
        const btn = document.createElement("button");
        btn.className = index === 0 ? "btn btn-info btn-sm customer-action-btn" : "btn btn-info btn-sm modal-action-btn";
        btn.id = index === 0 ? `remoteBtn-${idPel}` : `remoteBtnModal-${idPel}`;
        btn.textContent = "Remote ONT";

        btn.dataset.idPel = idPel;
        btn.dataset.ipServer = ipServer;
        btn.dataset.userServer = userServer;
        btn.dataset.passwordServer = passwordServer;
        btn.dataset.remoteIp = remoteIp;

        container.innerHTML = "";
        container.appendChild(btn);
        btn.addEventListener("click", handleRemoteClick);
    });
}

function handleRemoteClick(event) {
    const btn = event.currentTarget;

    // ambil data dari atribut
    const idPel = btn.dataset.idPel;
    const ipServer = btn.dataset.ipServer;
    const userServer = btn.dataset.userServer;
    const passwordServer = btn.dataset.passwordServer;
    const remoteIp = btn.dataset.remoteIp;

    // Tampilkan loading dulu - proses di ontremot.php bisa makan beberapa detik
    // (nunggu tunnel connect dll) dan responsenya baru muncul di iframe setelah
    // semuanya selesai, jadi tanpa ini iframe cuma keliatan blank/nge-freeze.
    const loadingEl = document.getElementById("remoteLoading");
    if (loadingEl) loadingEl.style.display = "flex";

    // buat form dinamis
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "proses/ontremot.php";
    form.target = "remoteFrame"; // hasil ditampilkan di iframe

    form.innerHTML = `
        <input type="hidden" name="idPel" value="${idPel}">
        <input type="hidden" name="remote_ip" value="${remoteIp}">
        <input type="hidden" name="ipServer" value="${ipServer}">
        <input type="hidden" name="userServer" value="${userServer}">
        <input type="hidden" name="passwordServer" value="${passwordServer}">
    `;

    document.body.appendChild(form);
    form.submit();

    // buka modal
    const modal = new bootstrap.Modal(document.getElementById("remoteModal"));
    modal.show();
}

function resetKoneksi(btn, idPel, pemilik, area, nama, nowa) {
    // Konfirmasi dari user (seperti disable)
    const confirmed = confirm(`Apakah Anda yakin ingin me-reset koneksi untuk ${idPel} (${nama})?\n\nUser akan disconnect dan reconnect otomatis.`);
    if (!confirmed) return;

    // Disable button dan show loading (seperti disable)
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = "Processing...";

    // POST ke endpoint reset (parameter sama seperti disable)
    fetch('proses/resetkoneksi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            IDPEL: idPel,
            PEMILIK: pemilik,
            AREA: area,
            NAMA: nama,
            NOWA: nowa
        })
    })
    .then(response => response.json().catch(() => null))
    .then(data => {
        if (!data) throw new Error('Invalid response');

        // Show result message (seperti disable)
        let msgClass = 'success';
        let msgIcon = '✅';

        if (data.status === 'error') {
            msgClass = 'danger';
            msgIcon = '❌';
        } else if (data.status === 'info') {
            msgClass = 'info';
            msgIcon = 'ℹ️';
        } else if (data.status === 'warning') {
            msgClass = 'warning';
            msgIcon = '⚠️';
        }

        const detailMsg = data.detail ? `<br><small>${data.detail}</small>` : '';
        const alertHtml = `
            <div class="alert alert-${msgClass} alert-dismissible fade show mt-2" role="alert">
                <strong>${msgIcon}</strong> ${data.message || 'Proses selesai'} ${detailMsg}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        // Insert alert after button
        btn.insertAdjacentHTML('afterend', alertHtml);

        // Log ke console
        console.log(`[Reset Koneksi] ${idPel}: ${data.message}`, data);
        
        // Refresh page jika berhasil (seperti disable)
        if (data.status === 'success') {
            setTimeout(() => {
                location.reload();
            }, 2000);
        }
    })
    .catch(error => {
        console.error('Reset koneksi error:', error);
        const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show mt-2" role="alert">
                <strong>❌</strong> Error: ${error.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        btn.insertAdjacentHTML('afterend', alertHtml);
    })
    .finally(() => {
        // Re-enable button
        btn.disabled = false;
        btn.innerText = originalText;
    });
}

function resetPemakaian(btn, idPel, nama) {
    const confirmed = confirm(`Reset counter pemakaian data untuk ${idPel} (${nama})?\n\nTotal pemakaian akan kembali ke 0 dan mulai dihitung ulang dari sekarang.`);
    if (!confirmed) return;

    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = "Processing...";

    fetch('getdata/reset_customer_usage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ idpel: idPel })
    })
    .then(response => response.json().catch(() => null))
    .then(data => {
        if (!data) throw new Error('Invalid response');

        const msgClass = data.status === 'success' ? 'success' : 'danger';
        const msgIcon = data.status === 'success' ? '✅' : '❌';
        const alertHtml = `
            <div class="alert alert-${msgClass} alert-dismissible fade show mt-2" role="alert">
                <strong>${msgIcon}</strong> ${data.message || 'Proses selesai'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        btn.insertAdjacentHTML('afterend', alertHtml);
        console.log(`[Reset Pemakaian] ${idPel}: ${data.message}`, data);
    })
    .catch(error => {
        console.error('Reset pemakaian error:', error);
        const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show mt-2" role="alert">
                <strong>❌</strong> Error: ${error.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        btn.insertAdjacentHTML('afterend', alertHtml);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerText = originalText;
    });
}
</script>



                    <script>
                        function cekRadwhoFallback(idPel) {
                            fetch(`getdata/cek_radius.php?idpel=${idPel}`)
                                .then(response => response.json())
                                .then(data => {
                                    let statusElement = document.getElementById(`data-status-${idPel}`);
                                    let infoElement = document.getElementById(`data-info-${idPel}`);
                                    let statusElement2 = document.getElementById(`data-status2-${idPel}`);
                                    let infoElement2 = document.getElementById(`data-info2-${idPel}`);
                                    let radwhoElement = document.getElementById(`data-radwho-${idPel}`);

                                    if (!statusElement || !infoElement || !statusElement2 || !infoElement2) return;

                                    if (data.status === "Online") {
                                        // Jika ditemukan online di RADIUS
                                        statusElement.innerHTML = `<span class="badge badge-sm bg-gradient-success">Connected via RADIUS</span>`;
                                        infoElement.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Aktif di FreeRADIUS
                    <br>${data.remote || ''}
                </span>`;
                                        statusElement2.innerHTML = `<span class="badge badge-sm bg-gradient-success">Connected via RADIUS</span>`;
                                        infoElement2.innerHTML = `<span class="text-secondary text-xs font-weight-bold">
                    <br>Aktif di FreeRADIUS
                    <br>${data.remote || ''}
                </span>`;
                                        if (radwhoElement) {
                                            radwhoElement.innerHTML = `
                        <span class="badge bg-gradient-success">🟢 Connected RADIUS</span>
                        <br><small>${data.remote || ''}</small>`;
                                        }
                                    } else {
                                        // Tetap LOS
                                        if (radwhoElement) {
                                            radwhoElement.innerHTML = `<span class="badge bg-gradient-secondary">⚪ Tidak Aktif di RADIUS</span>`;
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.error("❌ Gagal cek fallback radwho:", error);
                                });
                        }
                    </script>

                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <style>
                            

                                .small-text {
                                    font-size: 7px;
                                }
                                
                            </style>
















































































                            <table class="table align-items-center mb-0" style="font-size: 10px;"id="dataTable" >
                                <thead>
                                    <tr>
                                        <th  class="text-uppercase text-white text-xxs font-weight-bolder text-center">No</th>
                                        <th
                                            class="text-uppercase text-white text-xxs font-weight-bolder text-center">
                                            Name ID</th>
                                        <th
                                            class="text-uppercase text-white text-xxs font-weight-bolder ps-2 text-center">
                                            Status</th>
                                        <th
                                            class="text-uppercase text-white text-xxs font-weight-bolder ps-2 text-center">
                                            Server Area</th>
                                        <th
                                            class="text-uppercase text-white text-xxs font-weight-bolder ps-2 text-center">
                                            Packages</th>
                                    </tr>
                                </thead>
                                <tbody id="customerTableBody">










                                    <?php
                                    // Query + pagination + render row/modal pelanggan (inline).
                                    // Lazy-load memakai self-POST ke halaman ini juga, jadi logic ini satu-satunya sumber.
                                    $modalsBuffer = '';

                                    // Page size bisa dipilih user (default 15, paling ringan) -- whitelist
                                    // supaya tidak bisa disuntik angka sembarang lewat parameter.
                                    $pageSizeAllowed = [15, 25, 50, 100];
                                    $pageSizeDefault = 15;
                                    $pageSizeRequested = isset($_REQUEST['page_size']) ? (int)$_REQUEST['page_size'] : $pageSizeDefault;
                                    $pageSize = in_array($pageSizeRequested, $pageSizeAllowed, true) ? $pageSizeRequested : $pageSizeDefault;

                                    // $_REQUEST (bukan cuma $_POST) supaya nilai yang sama bisa datang lewat
                                    // GET setelah redirect PRG di bawah (lihat blok redirect).
                                    $page = isset($_REQUEST['page']) ? (int)$_REQUEST['page'] : 1;
                                    if ($page < 1) {
                                        $page = 1;
                                    }

                                    $rowsAction       = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
                                    // Pencarian global dari dashboard (form-dashboard-search) memakai field
                                    // "cariglobal", bukan "cari_pelanggan" - kalau tidak di-fallback, pencarian
                                    // dari dashboard dianggap tidak ada filter sama sekali dan hasilnya kosong.
                                    $rowsCariKeyword  = trim((string)($_REQUEST['cari_pelanggan'] ?? $_REQUEST['cariglobal'] ?? ''));
                                    $rowsFilterServer = trim((string)($_REQUEST['server'] ?? ''));
                                    $rowsFilterArea   = trim((string)($_REQUEST['area'] ?? ''));
                                    $rowsFilterOdp    = trim((string)($_REQUEST['cariodp'] ?? ''));
                                    $rowsFilterPaket  = trim((string)($_REQUEST['caripaket'] ?? ''));
                                    $rowsIsLosOnly    = ($rowsAction === 'cari_los');
                                    $rowsIsGlobalSearch = ($rowsAction === 'cari_global');

                                    $lazyPostPayload = [
                                        'action'         => $rowsAction !== '' ? $rowsAction : 'filter_pelanggan_unified',
                                        'cari_pelanggan' => $rowsCariKeyword,
                                        'server'         => $rowsFilterServer,
                                        'area'           => $rowsFilterArea,
                                        'cariodp'        => $rowsFilterOdp,
                                        'caripaket'      => $rowsFilterPaket,
                                        'page_size'      => $pageSize,
                                    ];

                                    // PRG (Post/Redirect/Get): form pencarian (#form-filter-pelanggan),
                                    // form LOS (#form-carilos) dan tombol Prev paging (#pagingPrevForm)
                                    // mengirim POST navigasi HALAMAN PENUH (bukan AJAX). Kalau langsung
                                    // di-render di sini, entry riwayat browser untuk tables.php jadi
                                    // berasal dari POST -> begitu user pencet tombol Back dari halaman
                                    // lain (mis. Edit Data), browser harus resubmit POST itu dan
                                    // menampilkan peringatan "Confirm Form Resubmission"/"Document
                                    // Expired" (muncul sebagai "error" ke user). Redirect ke URL GET
                                    // yang setara supaya riwayat browser cuma berisi GET (aman di-back
                                    // tanpa peringatan apapun). Fetch AJAX lazy-load "Next" mengirim
                                    // header X-Ajax-Fragment supaya TIDAK ikut di-redirect (dia butuh
                                    // fragment HTML langsung sebagai response, bukan 302).
                                    $rowsIsAjaxFragment = (($_SERVER['HTTP_X_AJAX_FRAGMENT'] ?? '') === '1');
                                    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && !$rowsIsAjaxFragment) {
                                        $prgParams = array_filter(
                                            $lazyPostPayload + ['page' => ($page > 1 ? $page : '')],
                                            static function ($v) { return $v !== '' && $v !== null; }
                                        );
                                        ob_end_clean();
                                        header('Location: tables.php' . ($prgParams ? ('?' . http_build_query($prgParams)) : ''));
                                        exit;
                                    }

                                    $rowsWhereParts = [];
                                    if ($AKSES === 'ASSISTANT') {
                                        $rowsScopeAreaList = (isset($area_list) && trim((string)$area_list) !== '') ? $area_list : "''";
                                        $rowsWhereParts[] = "p.AREA IN ($rowsScopeAreaList)";
                                    } else {
                                        $rowsOwnedPemilik = [];
                                        $qRowsOwn = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
                                        if ($qRowsOwn) {
                                            while ($r = mysqli_fetch_assoc($qRowsOwn)) {
                                                $p = trim((string)($r['PEMILIK'] ?? ''));
                                                if ($p !== '') {
                                                    $rowsOwnedPemilik[] = "'" . mysqli_real_escape_string($conn, $p) . "'";
                                                }
                                            }
                                        }
                                        $rowsOwnedPemilikList = count($rowsOwnedPemilik) > 0 ? implode(',', $rowsOwnedPemilik) : "''";
                                        $rowsWhereParts[] = "p.PEMILIK IN ($rowsOwnedPemilikList)";
                                    }

                                    if ($rowsCariKeyword !== '') {
                                        $rowsCariEsc = mysqli_real_escape_string($conn, $rowsCariKeyword);
                                        if ($rowsIsGlobalSearch) {
                                            // Pencarian global dari dashboard: cakup ID/Nama/ODP/Paket/No WA/Alamat
                                            $rowsWhereParts[] = "(p.IDPEL LIKE '%$rowsCariEsc%' OR p.NOWA LIKE '%$rowsCariEsc%' OR p.NAMA LIKE '%$rowsCariEsc%' OR p.ODP LIKE '%$rowsCariEsc%' OR p.PAKET LIKE '%$rowsCariEsc%' OR p.ALAMAT LIKE '%$rowsCariEsc%')";
                                        } else {
                                            $rowsWhereParts[] = "(p.IDPEL LIKE '%$rowsCariEsc%' OR p.NOWA LIKE '%$rowsCariEsc%' OR p.NAMA LIKE '%$rowsCariEsc%')";
                                        }
                                    }
                                    if ($rowsFilterServer !== '') {
                                        $rowsWhereParts[] = "p.PEMILIK = '" . mysqli_real_escape_string($conn, $rowsFilterServer) . "'";
                                    }
                                    if ($rowsFilterArea !== '') {
                                        $rowsWhereParts[] = "p.AREA = '" . mysqli_real_escape_string($conn, $rowsFilterArea) . "'";
                                    }
                                    if ($rowsFilterOdp !== '') {
                                        $rowsWhereParts[] = "p.ODP = '" . mysqli_real_escape_string($conn, $rowsFilterOdp) . "'";
                                    }
                                    if ($rowsFilterPaket !== '') {
                                        if (strtoupper($rowsFilterPaket) === 'EXPIRED') {
                                            $rowsTodaySql = date('Y-m-d');
                                            $rowsWhereParts[] = "p.TEMPO <= '$rowsTodaySql' AND NOT EXISTS (
                                                SELECT 1 FROM transaksi t
                                                WHERE t.IDPEL = p.IDPEL AND t.STATUS = 'BERHASIL' AND DATE(t.waktu) >= p.TEMPO
                                            )";
                                        } else {
                                            $rowsWhereParts[] = "p.PAKET = '" . mysqli_real_escape_string($conn, $rowsFilterPaket) . "'";
                                        }
                                    }

                                    $rowsBaseWhere = implode(' AND ', $rowsWhereParts);
                                    $pageRows = [];

                                    // Belum ada filter apapun (search/server/area/odp/paket/LOS) -> jangan tampilkan data apapun di awal load.
                                    $rowsHasAnyFilter = ($rowsCariKeyword !== '' || $rowsFilterServer !== '' || $rowsFilterArea !== '' || $rowsFilterOdp !== '' || $rowsFilterPaket !== '');

                                    if (!$rowsHasAnyFilter && !$rowsIsLosOnly) {
                                        $displayed_total = 0;
                                        $total_pages     = 1;
                                        $page            = 1;
                                    } elseif ($rowsIsLosOnly) {
                                        $rowsCandidateIdpel = [];
                                        $rowsServerPairs    = [];
                                        $qRowsCandidates = mysqli_query($conn, "SELECT p.IDPEL, p.PEMILIK, p.AREA FROM pelanggan p WHERE $rowsBaseWhere");
                                        if ($qRowsCandidates) {
                                            while ($row = mysqli_fetch_assoc($qRowsCandidates)) {
                                                $idpelC = (string)$row['IDPEL'];
                                                $rowsCandidateIdpel[$idpelC] = true;
                                                $pairKey = $row['AREA'] . '|' . $row['PEMILIK'];
                                                $rowsServerPairs[$pairKey] = ['area' => $row['AREA'], 'pemilik' => $row['PEMILIK']];
                                            }
                                        }

                                        // Cache status LOS dari cron (getdata/serverload.php, refresh tiap 1 menit,
                                        // sama seperti yang dipakai dashboard.php) -- jauh lebih cepat daripada
                                        // connect langsung ke Mikrotik satu-satu tiap kali tombol LOS diklik.
                                        // Fallback ke live Mikrotik hanya kalau cache belum ada/rusak.
                                        $rowsLosCacheName = ($AKSES === 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : ($ceknama ?? '');
                                        $rowsLosCacheFile = __DIR__ . '/serverlog/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$rowsLosCacheName) . '.txt';
                                        $rowsLosFromCache = null;
                                        if ($rowsLosCacheName !== '' && is_file($rowsLosCacheFile)) {
                                            $rowsLosCacheRaw = @file_get_contents($rowsLosCacheFile);
                                            $rowsLosCacheDecoded = json_decode((string)$rowsLosCacheRaw, true);
                                            if (is_array($rowsLosCacheDecoded) && isset($rowsLosCacheDecoded['los_ids']) && is_array($rowsLosCacheDecoded['los_ids'])) {
                                                $rowsLosFromCache = array_flip(array_map('strval', $rowsLosCacheDecoded['los_ids']));
                                            }
                                        }

                                        if ($rowsLosFromCache !== null) {
                                            $rowsOfflineIdpel = array_values(array_intersect_key($rowsCandidateIdpel, $rowsLosFromCache));
                                        } else {
                                            require_once __DIR__ . '/routeros_api.class.php';

                                            $rowsOnlineUsernames = [];
                                            foreach ($rowsServerPairs as $pair) {
                                                $rowsAreaEsc    = mysqli_real_escape_string($conn, $pair['area']);
                                                $rowsPemilikEsc = mysqli_real_escape_string($conn, $pair['pemilik']);
                                                $qRowsSrv = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD FROM server WHERE AREA = '$rowsAreaEsc' AND PEMILIK = '$rowsPemilikEsc' LIMIT 1");
                                                $rowsSrv = $qRowsSrv ? mysqli_fetch_assoc($qRowsSrv) : null;
                                                if (!$rowsSrv) {
                                                    continue;
                                                }

                                                $rowsApi = new RouterosAPI();
                                                $rowsApi->timeout = 3;
                                                if (@$rowsApi->connect($rowsSrv['IP'], $rowsSrv['PEMILIK'], $rowsSrv['PASSWORD'])) {
                                                    $rowsActive = $rowsApi->comm('/ppp/active/print');
                                                    if (is_array($rowsActive)) {
                                                        foreach ($rowsActive as $entry) {
                                                            if (!empty($entry['name'])) {
                                                                $rowsOnlineUsernames[$entry['name']] = true;
                                                            }
                                                        }
                                                    }
                                                    $rowsApi->disconnect();
                                                }
                                            }

                                            $rowsOfflineIdpel = array_values(array_diff(array_keys($rowsCandidateIdpel), array_keys($rowsOnlineUsernames)));
                                        }
                                        sort($rowsOfflineIdpel);

                                        $displayed_total = count($rowsOfflineIdpel);
                                        $total_pages     = max(1, (int)ceil($displayed_total / $pageSize));
                                        $page             = min($page, $total_pages);
                                        $rowsOffset       = ($page - 1) * $pageSize;
                                        $rowsPageIdpel    = array_slice($rowsOfflineIdpel, $rowsOffset, $pageSize);

                                        if (count($rowsPageIdpel) > 0) {
                                            $rowsIdpelEscaped = array_map(function ($v) use ($conn) {
                                                return "'" . mysqli_real_escape_string($conn, $v) . "'";
                                            }, $rowsPageIdpel);
                                            $qRowsData = mysqli_query($conn, "SELECT p.* FROM pelanggan p WHERE p.IDPEL IN (" . implode(',', $rowsIdpelEscaped) . ") ORDER BY p.IDPEL ASC");
                                            if ($qRowsData) {
                                                while ($row = mysqli_fetch_assoc($qRowsData)) {
                                                    $pageRows[] = $row;
                                                }
                                            }
                                        }
                                    } else {
                                        $qRowsCount = mysqli_query($conn, "SELECT COUNT(*) AS total FROM pelanggan p WHERE $rowsBaseWhere");
                                        $rowsCountRow = $qRowsCount ? mysqli_fetch_assoc($qRowsCount) : ['total' => 0];
                                        $displayed_total = (int)($rowsCountRow['total'] ?? 0);
                                        $total_pages     = max(1, (int)ceil($displayed_total / $pageSize));
                                        $page             = min($page, $total_pages);
                                        $rowsOffset       = ($page - 1) * $pageSize;

                                        $qRowsData = mysqli_query($conn, "SELECT p.* FROM pelanggan p WHERE $rowsBaseWhere ORDER BY p.IDPEL ASC LIMIT $pageSize OFFSET $rowsOffset");
                                        if ($qRowsData) {
                                            while ($row = mysqli_fetch_assoc($qRowsData)) {
                                                $pageRows[] = $row;
                                            }
                                        }
                                    }

                                    if (!$rowsHasAnyFilter && !$rowsIsLosOnly) {
                                        echo '<tr><td colspan="5" class="text-center text-secondary py-4">Gunakan pencarian atau filter di atas untuk menampilkan data pelanggan.</td></tr>';
                                    }

                                    // Helper: format tanggal ke format Indonesia "1 Juli 2026" (+ jam jika ada),
                                    // dipakai supaya semua tanggal pelanggan (created, bayar terakhir, dll) seragam.
                                    if (!function_exists('format_tanggal_indo')) {
                                        function format_tanggal_indo($value, $withTime = true)
                                        {
                                            $value = trim((string) $value);
                                            if ($value === '' || $value === '-' || strtoupper($value) === 'N/A') return $value;

                                            $bulanIndo = [
                                                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                                                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                                            ];
                                            $bulanEngMap = [
                                                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7,
                                                'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12
                                            ];

                                            $timestamp = null;

                                            // Format RouterOS: mon/dd/yyyy hh:mm:ss (mis. jul/19/2026 05:55:41)
                                            if (preg_match('/^([a-zA-Z]{3,4})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
                                                $monKey = strtolower($m[1]);
                                                if (isset($bulanEngMap[$monKey])) {
                                                    $timestamp = mktime(
                                                        isset($m[4]) ? (int) $m[4] : 0,
                                                        isset($m[5]) ? (int) $m[5] : 0,
                                                        isset($m[6]) ? (int) $m[6] : 0,
                                                        $bulanEngMap[$monKey],
                                                        (int) $m[2],
                                                        (int) $m[3]
                                                    );
                                                }
                                            }

                                            if ($timestamp === null) {
                                                $ts = strtotime($value);
                                                if ($ts !== false) $timestamp = $ts;
                                            }

                                            if ($timestamp === null) return $value;

                                            $result = (int) date('j', $timestamp) . ' ' . $bulanIndo[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
                                            if ($withTime && date('H:i:s', $timestamp) !== '00:00:00') {
                                                $result .= ' ' . date('H:i', $timestamp);
                                            }

                                            return $result;
                                        }
                                    }

                                    // Hitung "jatuh tempo berikutnya" per pelanggan sesuai TIPE_TEMPO
                                    // masing-masing, pakai fungsi PERSIS sama dengan yang dipakai cron
                                    // isolir otomatis (tagihan_status_lib.php) -- supaya tanggal yang
                                    // tampil di sini selalu konsisten dengan yang dipakai utk enforcement,
                                    // bukan logika duplikat yang bisa menyimpang (lihat kasus lama
                                    // checkProvisioningReady()).
                                    require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';

                                    $rowsAllIdpel = [];
                                    foreach ($pageRows as $rowForIdpel) {
                                        $rowsAllIdpel[] = (string)$rowForIdpel['IDPEL'];
                                    }
                                    $rowsLastPaymentMap   = tagihanGetLastPaymentsBulk($conn, $rowsAllIdpel);
                                    $rowsLastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, $rowsAllIdpel);

                                    // Paket FREE/FASUM (harga 0) yang TIDAK sedang promo -> tampil "Lifetime"
                                    // (tidak pernah ditagih, sama seperti pengecualian di cek_tagihan_harian.php).
                                    // Paket yang SEDANG promo -> tampilkan tanggal berakhirnya masa promo
                                    // (dihitung sama persis dengan notifbot/notifphp/crontab_promo.php).
                                    list(, $rowsFasumPaketList, $rowsPromoPaketIds) = tagihanLoadPaketMaps($conn);
                                    $rowsPromoConfigMap = tagihanLoadPromoConfigMap($conn);

                                    // Cache pengaturan per-pemilik (hari jatuh tempo fixed, waktu tunggu
                                    // prabayar, toggle "monthversary ikut tanggal bayar terakhir") supaya
                                    // tidak baca file JSON berulang untuk pemilik yang sama.
                                    $rowsOwnerTagihanSettingsCache = [];
                                    if (!function_exists('tablesLoadOwnerTagihanSettings')) {
                                        function tablesLoadOwnerTagihanSettings($pemilik)
                                        {
                                            global $rowsOwnerTagihanSettingsCache, $conn;
                                            if (isset($rowsOwnerTagihanSettingsCache[$pemilik])) {
                                                return $rowsOwnerTagihanSettingsCache[$pemilik];
                                            }
                                            $dataDir = __DIR__ . '/notifbot/data';

                                            // Fixed Due Date SEKARANG disimpan di tabel database `reminder_settings`
                                            // (bukan lagi murni file JSON) -- baca via reminderSettingsGet() (helper
                                            // terpusat, notifbot/reminder_settings_helper.php) supaya SELALU dapat
                                            // nilai jatuh_tempo yang benar-benar ter-setting akun ini. Dulu di sini
                                            // parsing manual file mirror JSON reminder-<pemilik>.json -- rapuh krn
                                            // mirror itu SENGAJA menghilangkan key 'jatuh_tempo' utk akun yang belum
                                            // eksplisit setting Fixed Due Date (lihat reminderSettingsSyncJsonMirror()),
                                            // jadi jatuh ke fallback hardcode 25 walau DB sudah punya nilai yang benar.
                                            require_once __DIR__ . '/notifbot/reminder_settings_helper.php';
                                            // $pemilik di sini adalah kolom PEMILIK pelanggan/server (bisa berupa
                                            // kunci internal per-brand utk server mode RADIUS_ONLY), BUKAN
                                            // username login pemilik akun -- WAJIB resolve dulu ke username
                                            // login sebenarnya sebelum baca reminder_settings, kalau tidak
                                            // selalu jatuh ke default krn baris DB dikunci per username login
                                            // (lihat reminderSettingsResolveOwnerUsername()).
                                            $pemilikLoginUsername = reminderSettingsResolveOwnerUsername($conn, $pemilik);
                                            $jatuhTempoHari = (int) reminderSettingsGet($conn, $pemilikLoginUsername)['jatuh_tempo'];

                                            // File-file ini juga ditulis paymentset.php pakai username login
                                            // ($ceknama), sama seperti reminder_settings -- pakai username hasil
                                            // resolve yang sama supaya konsisten (lihat komentar di atas).
                                            $graceP = 2;
                                            $graceFile = "$dataDir/prabayar_grace_period-$pemilikLoginUsername.json";
                                            if (file_exists($graceFile)) {
                                                $graceData = json_decode(file_get_contents($graceFile), true);
                                                if (is_array($graceData) && isset($graceData['prabayar_grace_period'])) {
                                                    $graceP = (int)$graceData['prabayar_grace_period'];
                                                }
                                            }

                                            $followLast = false;
                                            $monthFile = "$dataDir/monthversary_setting-$pemilikLoginUsername.json";
                                            if (file_exists($monthFile)) {
                                                $monthData = json_decode(file_get_contents($monthFile), true);
                                                if (is_array($monthData) && isset($monthData['follow_last_payment'])) {
                                                    $followLast = !empty($monthData['follow_last_payment']);
                                                }
                                            }

                                            $settings = [
                                                'jatuh_tempo_hari' => $jatuhTempoHari,
                                                'prabayar_grace_period' => $graceP,
                                                'monthversary_follow_last_payment' => $followLast,
                                            ];
                                            $rowsOwnerTagihanSettingsCache[$pemilik] = $settings;
                                            return $settings;
                                        }
                                    }

                                    foreach ($pageRows as $data1) {
                                        $idpel   = (string)$data1['IDPEL'];
                                        $pemilik = (string)($data1['PEMILIK'] ?? '');
                                        $area    = (string)($data1['AREA'] ?? '');

                                        $brand = '';
                                        $ip = $srvUser = $srvPassword = '';
                                        if ($pemilik !== '' && $area !== '') {
                                            $areaEsc    = mysqli_real_escape_string($conn, $area);
                                            $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
                                            $qSrv = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD, BRAND FROM server WHERE AREA = '$areaEsc' AND PEMILIK = '$pemilikEsc' LIMIT 1");
                                            $srv = $qSrv ? mysqli_fetch_assoc($qSrv) : null;
                                            if ($srv) {
                                                $brand       = (string)($srv['BRAND'] ?? '');
                                                $ip          = (string)($srv['IP'] ?? '');
                                                $srvUser     = (string)($srv['PEMILIK'] ?? '');
                                                $srvPassword = (string)($srv['PASSWORD'] ?? '');
                                            }
                                        }

                                        $idpelAttr   = htmlspecialchars($idpel, ENT_QUOTES, 'UTF-8');
                                        $namaAttr    = htmlspecialchars((string)($data1['NAMA'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $nowaAttr    = htmlspecialchars((string)($data1['NOWA'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $alamatAttr  = htmlspecialchars((string)($data1['ALAMAT'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $emailAttr   = htmlspecialchars((string)($data1['EMAIL'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $tikorAttr   = htmlspecialchars((string)($data1['TIKOR'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $odpAttr     = htmlspecialchars((string)($data1['ODP'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $paketAttr   = htmlspecialchars((string)($data1['PAKET'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $modeAttr    = htmlspecialchars((string)($data1['MODE'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $pemilikAttr = htmlspecialchars($pemilik, ENT_QUOTES, 'UTF-8');
                                        $areaAttr    = htmlspecialchars($area, ENT_QUOTES, 'UTF-8');
                                        $brandAttr   = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
                                        $salesAttr   = htmlspecialchars((string)($data1['sales'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $passwordAttr = htmlspecialchars((string)($data1['PASSWORD'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $nikAttr        = htmlspecialchars((string)($data1['NIK'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $provinsiAttr   = htmlspecialchars((string)($data1['provinsi'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $kabupatenAttr  = htmlspecialchars((string)($data1['kabupaten'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $kecamatanAttr  = htmlspecialchars((string)($data1['kecamatan'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $kelurahanAttr  = htmlspecialchars((string)($data1['kelurahan'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $rwAttr         = htmlspecialchars((string)($data1['rw'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $rtAttr         = htmlspecialchars((string)($data1['rt'] ?? ''), ENT_QUOTES, 'UTF-8');

                                        $tanggalPasang = (string)($data1['TANGGALPASANG'] ?? '');
                                        $agingLabel = '';
                                        if ($tanggalPasang !== '') {
                                            try {
                                                $datetimePasang = new DateTime($tanggalPasang);
                                                $datetimeNow    = new DateTime();
                                                $selisih        = $datetimePasang->diff($datetimeNow);
                                                if ($selisih->y >= 1) {
                                                    $agingLabel = " <span style='color:red;'>(lebih dari " . $selisih->y . " tahun " . $selisih->m . " bulan)</span>";
                                                } else {
                                                    $agingLabel = " <span style='color:green;'>(baru " . $selisih->m . " bulan)</span>";
                                                }
                                            } catch (Exception $e) {
                                                $agingLabel = '';
                                            }
                                        }

                                        $tipeBayar = (string)($data1['TIPE_BAYAR'] ?? '');
                                        $tipeBayarBadge = ($tipeBayar === 'prabayar') ? 'bg-success' : 'bg-primary';
                                        $tipeTempo = (string)($data1['TIPE_TEMPO'] ?? '');
                                        $tipeTempoBadge = 'bg-info';
                                        $tipeTempoLabel = 'Rolling Due Date';
                                        if ($tipeTempo === 'mengikuti_tanggal_tempo') {
                                            $tipeTempoBadge = 'bg-secondary';
                                            $tipeTempoLabel = 'Fixed Due Date';
                                        } elseif ($tipeTempo === 'monthversary') {
                                            $tipeTempoBadge = 'bg-primary';
                                            $tipeTempoLabel = 'Monthversary Due Date';
                                        }

                                        // Jatuh tempo berikutnya, dihitung sesuai TIPE_TEMPO masing-masing
                                        // pelanggan. Pakai tagihanHitungJatuhTempoBerikutnya() (murni hitung
                                        // tanggal utk tampilan), BUKAN tagihanHitungStatus() -- fungsi itu
                                        // dipakai cron isolir dan sengaja mengosongkan jatuh_tempo kalau
                                        // pelanggan sudah bayar di periode berjalan (short-circuit "baru bayar
                                        // bulan ini"), yang bikin tanggal tidak muncul di sini walau sudah bayar.
                                        //
                                        // Dua pengecualian sebelum masuk ke perhitungan per-mode:
                                        // - Paket FREE/FASUM (harga 0) yang TIDAK promo -> "Lifetime" (tidak
                                        //   pernah ditagih sama sekali, konsisten dgn pengecualian enforcement
                                        //   di cek_tagihan_harian.php/sync_freeradius_users.php).
                                        // - Paket yang SEDANG promo -> tanggal berakhirnya masa promo (paket
                                        //   otomatis berganti ke paket_pengganti setelah itu, lihat crontab_promo.php),
                                        //   BUKAN jatuh tempo per TIPE_TEMPO -- selama promo, tagihan ikut aturan promo.
                                        $paketKeyLower = strtolower(trim((string)($data1['PAKET'] ?? '')));
                                        $promoConfigRow = $rowsPromoConfigMap[$paketKeyLower] ?? null;
                                        $isJatuhTempoLifetime = false;
                                        $isJatuhTempoPromo = false;
                                        $jatuhTempoMundurHtml = '';

                                        if ($promoConfigRow !== null) {
                                            $isJatuhTempoPromo = true;
                                            $jatuhTempoBerikutnya = (string) (tagihanComputePromoEndDate($conn, $idpel, $tanggalPasang, $promoConfigRow) ?? '');
                                        } elseif (tagihanIsFasumNonPromo($paketKeyLower, $rowsFasumPaketList, $rowsPromoPaketIds)) {
                                            $isJatuhTempoLifetime = true;
                                            $jatuhTempoBerikutnya = '';
                                        } else {
                                            $rowsOwnerSettings = tablesLoadOwnerTagihanSettings($pemilik);
                                            $jatuhTempoBerikutnya = tagihanHitungJatuhTempoBerikutnya($conn, [
                                                'IDPEL' => $idpel,
                                                'TANGGALPASANG' => $tanggalPasang,
                                                'TIPE_BAYAR' => $tipeBayar,
                                                'TIPE_TEMPO' => $tipeTempo,
                                                'TEMPO' => (string)($data1['TEMPO'] ?? ''),
                                                'TANGGAL_MONTHVERSARY' => (string)($data1['TANGGAL_MONTHVERSARY'] ?? ''),
                                            ], [
                                                'jatuh_tempo_hari' => $rowsOwnerSettings['jatuh_tempo_hari'],
                                                'lastPaymentMap' => $rowsLastPaymentMap,
                                                'lastPaidUsageMap' => $rowsLastPaidUsageMap,
                                                'monthversary_follow_last_payment' => $rowsOwnerSettings['monthversary_follow_last_payment'],
                                            ]);

                                            // PAKSA utk Fixed Due Date: selalu tanggal jatuh_tempo_hari yang
                                            // dikonfigurasi admin (siklus bulan berjalan/berikutnya dari HARI
                                            // INI), BUKAN hasil tagihanHitungJatuhTempoBerikutnya() di atas --
                                            // yang utk prabayar bisa "nyangkut" ke bulan PENGUNAAN invoice
                                            // terakhir yang lunas (bisa beda dari siklus jatuh_tempo_hari
                                            // berjalan kalau pelanggan bayar jauh di awal/akhir siklus).
                                            if ($tipeTempo === 'mengikuti_tanggal_tempo') {
                                                $fixedDueDayPaksa = (int) $rowsOwnerSettings['jatuh_tempo_hari'];
                                                $todayTsPaksa = strtotime(date('Y-m-d'));
                                                $dueMonthTsPaksa = ((int) date('j', $todayTsPaksa) <= $fixedDueDayPaksa)
                                                    ? $todayTsPaksa
                                                    : strtotime('+1 month', $todayTsPaksa);
                                                $jatuhTempoPaksa = tagihanBuildMonthlyDate(
                                                    (int) date('Y', $dueMonthTsPaksa),
                                                    (int) date('n', $dueMonthTsPaksa),
                                                    $fixedDueDayPaksa
                                                );
                                                if (!empty($jatuhTempoPaksa)) {
                                                    $jatuhTempoBerikutnya = $jatuhTempoPaksa;
                                                }
                                            }

                                            // Toggle "Monthversary ikut tanggal bayar terakhir" (ON) bisa
                                            // menggeser jatuh tempo berikutnya (krn ada yg bayar TELAT -- lihat
                                            // tagihanHitungStatus()/tagihanHitungJatuhTempoBerikutnya()). Supaya
                                            // admin tetap lihat tanggal ANCHOR ASLI-nya (bukan cuma yg sudah
                                            // bergeser), hitung ulang PAKSA tanpa toggle utk dibandingkan --
                                            // kalau beda, tampilkan anchor asli sbg teks utama + tanda merah
                                            // "(mundur N hari, jadi tanggal ...)" di belakangnya.
                                            if ($tipeTempo === 'monthversary' && !empty($rowsOwnerSettings['monthversary_follow_last_payment']) && $jatuhTempoBerikutnya !== '') {
                                                $jatuhTempoAnchorAsli = tagihanHitungJatuhTempoBerikutnya($conn, [
                                                    'IDPEL' => $idpel,
                                                    'TANGGALPASANG' => $tanggalPasang,
                                                    'TIPE_BAYAR' => $tipeBayar,
                                                    'TIPE_TEMPO' => $tipeTempo,
                                                    'TEMPO' => (string)($data1['TEMPO'] ?? ''),
                                                    'TANGGAL_MONTHVERSARY' => (string)($data1['TANGGAL_MONTHVERSARY'] ?? ''),
                                                ], [
                                                    'jatuh_tempo_hari' => $rowsOwnerSettings['jatuh_tempo_hari'],
                                                    'lastPaymentMap' => $rowsLastPaymentMap,
                                                    'lastPaidUsageMap' => $rowsLastPaidUsageMap,
                                                    'monthversary_follow_last_payment' => false,
                                                ]);
                                                if ($jatuhTempoAnchorAsli !== '' && $jatuhTempoAnchorAsli !== $jatuhTempoBerikutnya) {
                                                    $selisihHariMundur = (int) round((strtotime($jatuhTempoBerikutnya) - strtotime($jatuhTempoAnchorAsli)) / 86400);
                                                    if ($selisihHariMundur > 0) {
                                                        $jatuhTempoMundurHtml = ' <span class="text-danger fw-bold">(mundur ' . $selisihHariMundur . ' hari, jadi tanggal ' . htmlspecialchars(format_tanggal_indo($jatuhTempoBerikutnya, false), ENT_QUOTES, 'UTF-8') . ')</span>';
                                                        $jatuhTempoBerikutnya = $jatuhTempoAnchorAsli;
                                                    }
                                                }
                                            }
                                        }
                                        // Nilai awal utk date-picker edit: pelanggan Monthversary pakai anchor
                                        // yang sudah tersimpan, mode lain pakai tanggal jatuh tempo hasil hitung
                                        // (titik awal wajar utk digeser admin).
                                        $editTempoDefaultDate = ($tipeTempo === 'monthversary')
                                            ? (string)($data1['TANGGAL_MONTHVERSARY'] ?? '')
                                            : $jatuhTempoBerikutnya;
                                        $editTempoDefaultDateAttr = htmlspecialchars($editTempoDefaultDate, ENT_QUOTES, 'UTF-8');

                                        // Tombol "Ubah Jatuh Tempo" cuma relevan utk Rolling & Monthversary --
                                        // jatuh tempo Fixed Due Date sama utk SEMUA pelanggan (1 pengaturan
                                        // global), jadi tidak ada yang bisa diedit per-pelanggan di mode ini.
                                        $editTempoPencilHtml = '';
                                        if ($tipeTempo !== 'mengikuti_tanggal_tempo') {
                                            $editTempoPencilHtml = ' <a href="javascript:void(0)" onclick="event.stopPropagation(); openEditMonthversaryModal(\'' . $idpelAttr . '\', \'' . $editTempoDefaultDateAttr . '\', \'' . $tipeTempo . '\')" title="Ubah tanggal jatuh tempo"><i class="fas fa-pen text-secondary" style="font-size:15px;"></i></a>';
                                        }

                                        // Pembayaran terakhir yang berhasil, diurutkan berdasarkan periode
                                        // PENGUNAAN (bulan-tahun pemakaian) - bukan berdasarkan tanggal transaksi,
                                        // supaya periode yang tampil selalu periode pemakaian paling akhir.
                                        $lastPaymentText = '';
                                        $qLastPayment = mysqli_query($conn, "SELECT waktu, HARGA, PENGUNAAN FROM transaksi
                                            WHERE IDPEL = '" . mysqli_real_escape_string($conn, $idpel) . "' AND STATUS = 'BERHASIL'
                                            ORDER BY RIGHT(PENGUNAAN, 4) DESC,
                                                FIELD(LEFT(PENGUNAAN, LOCATE(' ', PENGUNAAN) - 1),
                                                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                                                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') DESC
                                            LIMIT 1");
                                        if ($qLastPayment && ($lastPaymentRow = mysqli_fetch_assoc($qLastPayment))) {
                                            $lastPaymentDate = format_tanggal_indo($lastPaymentRow['waktu']);
                                            $lastPaymentHarga = (float)($lastPaymentRow['HARGA'] ?? 0);
                                            $lastPaymentPeriode = trim((string)($lastPaymentRow['PENGUNAAN'] ?? ''));
                                            $lastPaymentText = $lastPaymentDate . ' - Rp' . number_format($lastPaymentHarga, 0, ',', '.');
                                            if ($lastPaymentPeriode !== '' && $lastPaymentPeriode !== '-') {
                                                $lastPaymentText .= ' (Periode: ' . $lastPaymentPeriode . ')';
                                            }
                                        }

                                        echo '<tr id="customerRow-' . $idpelAttr . '">';
                                        echo '<td class="text-center" style="height:1px;">';
                                        echo '<div class="row-no-icon-wrap" data-bs-toggle="modal" data-bs-target="#exampleoverview' . $idpelAttr . '"'
                                            . ' style="cursor:pointer;" onclick="openMonitorModal(this, \'' . $idpelAttr . '\', \'' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '\', \'' . htmlspecialchars($srvUser, ENT_QUOTES, 'UTF-8') . '\', \'' . htmlspecialchars($srvPassword, ENT_QUOTES, 'UTF-8') . '\')">';
                                        echo '<span class="row-number-cell"></span>';
                                        echo '<img src="https://img.icons8.com/stickers/100/add-user-male.png" class="avatar" alt="Customer" width="28" height="28">';
                                        echo '</div>';
                                        echo '</td>';

                                        echo '<td style="width:100px;">';
                                        echo '<div class="d-flex flex-column px-2 py-1" data-bs-toggle="modal" data-bs-target="#exampleoverview' . $idpelAttr . '"'
                                            . ' style="cursor:pointer;" onclick="openMonitorModal(this, \'' . $idpelAttr . '\', \'' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '\', \'' . htmlspecialchars($srvUser, ENT_QUOTES, 'UTF-8') . '\', \'' . htmlspecialchars($srvPassword, ENT_QUOTES, 'UTF-8') . '\')">';
                                        echo '<div class="text-center mb-1">';
                                        echo '<h6 class="mb-0 small-text" style="font-size:6px;">' . $idpelAttr . '</h6>';
                                        echo '<p class="text-sm text-secondary mb-0 small-text" style="font-size:6px;">' . $namaAttr . '</p>';
                                        echo '<p class="text-sm text-secondary mb-0 small-text" style="font-size:6px;">' . $nowaAttr . '</p>';
                                        echo '</div>';
                                        echo '</div>';
                                        echo '</td>';

                                        echo '<td class="align-middle text-center text-sm">';
                                        echo '<div class="status-action-row">';
                                        if ($AKSES === 'ADMIN') {
                                            echo '<button type="button" class="btn btn-warning btn-sm customer-action-btn" data-perm="btn_cust_buat_tiket"'
                                                . ' data-idpel="' . $idpelAttr . '" data-brand="' . $brandAttr . '" data-nowa="' . $nowaAttr . '"'
                                                . ' data-nama="' . $namaAttr . '" data-alamat="' . $alamatAttr . '" data-email="' . $emailAttr . '"'
                                                . ' data-tikor="' . $tikorAttr . '" data-odp="' . $odpAttr . '" onclick="event.stopPropagation(); openModal(this)">Buat Tiket</button>';
                                        }
                                        echo '<button type="button" class="btn btn-danger btn-sm customer-action-btn" data-bs-toggle="modal" data-bs-target="#examplelivechat' . $idpelAttr . '" onclick="event.stopPropagation();">Live Chat</button>';
                                        echo '<div id="remoteContainer-' . $idpelAttr . '"></div>';
                                        echo '</div>';
                                        echo '<div class="status-top-badges">';
                                        echo '<span id="data-status2-' . $idpelAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                                        echo '<div id="data-paket-aktif-' . $idpelAttr . '"></div>';
                                        echo '<div id="data-sla-' . $idpelAttr . '"><span class="badge badge-sm bg-gradient-info">SLA PELANGGAN 0.00%</span></div>';
                                        echo '</div>';
                                        echo '<span id="data-realtime-' . $idpelAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';
                                        echo '<span id="data-info2-' . $idpelAttr . '" style="display:none;"></span>';
                                        if ($ip !== '' && $srvUser !== '') {
                                            echo '<script>startFetching("' . $idpelAttr . '", "' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '", "' . htmlspecialchars($srvUser, ENT_QUOTES, 'UTF-8') . '", "' . htmlspecialchars($srvPassword, ENT_QUOTES, 'UTF-8') . '", "' . $modeAttr . '");</script>';
                                        }
                                        echo '</td>';

                                        echo '<td>';
                                        echo '<p class="text-xs font-weight-bold mb-0">' . $brandAttr . '</p>';
                                        echo '<p class="text-xs text-secondary mb-0">' . $areaAttr . '</p>';
                                        echo '<p class="text-xs text-secondary mb-0">' . $odpAttr . '</p>';
                                        echo '</td>';

                                        echo '<td>';
                                        echo '<span class="text-secondary text-xs font-weight-bold">Created : ' . htmlspecialchars(format_tanggal_indo($tanggalPasang, false), ENT_QUOTES, 'UTF-8') . $agingLabel . '</span>';
                                        echo '<p class="text-xs font-weight mb-0">Registed : ' . $paketAttr . '</p>';
                                        echo '<p class="text-xs font-weight mb-0">Tipe bayar : <span class="badge ' . $tipeBayarBadge . '">' . htmlspecialchars($tipeBayar, ENT_QUOTES, 'UTF-8') . '</span></p>';
                                        echo '<p class="text-xs font-weight mb-0">Tipe tempo : <span class="badge ' . $tipeTempoBadge . '">' . htmlspecialchars($tipeTempoLabel, ENT_QUOTES, 'UTF-8') . '</span></p>';
                                        if ($isJatuhTempoLifetime) {
                                            echo '<p class="text-xs font-weight mb-0">Jatuh tempo berikutnya : Lifetime</p>';
                                        } elseif ($isJatuhTempoPromo) {
                                            echo '<p class="text-xs font-weight mb-0">Jatuh tempo (promo s.d.) : ' . ($jatuhTempoBerikutnya !== '' ? htmlspecialchars(format_tanggal_indo($jatuhTempoBerikutnya, false), ENT_QUOTES, 'UTF-8') : '-') . '</p>';
                                        } elseif ($jatuhTempoBerikutnya !== '') {
                                            echo '<p class="text-xs font-weight mb-0">Jatuh tempo berikutnya : ' . htmlspecialchars(format_tanggal_indo($jatuhTempoBerikutnya, false), ENT_QUOTES, 'UTF-8');
                                            echo $editTempoPencilHtml;
                                            echo $jatuhTempoMundurHtml;
                                            echo '</p>';
                                        }
                                        if ($AKSES === 'ADMIN') {
                                            echo '<div class="tiket total" data-id="' . $idpelAttr . '">Loading...</div>';
                                        }
                                        if ($lastPaymentText !== '') {
                                            echo '<p class="text-xs font-weight mb-0 text-success"><i class="fas fa-check-circle"></i> Bayar terakhir : ' . htmlspecialchars($lastPaymentText, ENT_QUOTES, 'UTF-8') . '</p>';
                                        }
                                        echo '</td>';
                                        echo '</tr>';

                                        $formId = 'dataForm' . $idpel;
                                        $nohp = $data1['NOWA'] ?? '';
                                        $hp = '';
                                        if (!preg_match('/[^+0-9]/', trim((string)$nohp))) {
                                            $trimmedHp = trim((string)$nohp);
                                            if (substr($trimmedHp, 0, 2) === '62') {
                                                $hp = $trimmedHp;
                                            } elseif (substr($trimmedHp, 0, 3) === '+62') {
                                                $hp = '62' . substr($trimmedHp, 1);
                                            } elseif (substr($trimmedHp, 0, 1) === '0') {
                                                $hp = '62' . substr($trimmedHp, 1);
                                            } else {
                                                $hp = $trimmedHp;
                                            }
                                        }
                                        $hpAttr = htmlspecialchars($hp, ENT_QUOTES, 'UTF-8');

                                        $modal  = '<div class="modal fade" id="exampleoverview' . $idpelAttr . '" tabindex="-1" aria-labelledby="exampleModalLabel' . $idpelAttr . '" aria-hidden="true" data-bs-backdrop="false">';
                                        $modal .= '<div class="modal-dialog modal-dialog-centered overview-modal-dialog"><div class="modal-content overview-modal-content">';
                                        $modal .= '<div class="modal-header"><h5 class="modal-title" id="exampleModalLabel' . $idpelAttr . '">Overview Customer data</h5>';
                                        $modal .= '<button type="button" class="btn-close overview-close-btn" data-bs-dismiss="modal" aria-label="Close"></button></div>';
                                        $modal .= '<div class="modal-body overview-modal-body"><div class="row overview-main-row">';

                                        $modal .= '<div class="col-12 col-lg-4 overview-profile-col">';
                                        $modal .= '<label class="form-label">Customer Location</label><div id="map' . $idpelAttr . '" style="height:150px;"></div>';
                                        $modal .= '<label class="form-label mt-2">Customer Traffic</label><canvas style="height:100px;" id="trafficChart' . $idpelAttr . '"></canvas>';
                                        $modal .= '<label class="form-label mt-2">Customer Transaction history</label>';
                                        $modal .= '<iframe class="customer-transaction-frame" src="customertransation.php?idpel=' . urlencode($idpel) . '"></iframe>';
                                        $modal .= '<a class="btn btn-secondary btn-sm mt-2" href="Transaction.php?idpel=' . urlencode($idpel) . '">See all transaction</a>';
                                        $modal .= '</div>';

                                        $modal .= '<div class="col-12 col-lg-4 overview-formdata-col">';
                                        $modal .= '<form id="' . $formId . '" class="dataForm p-0">';
                                        $modal .= '<input type="hidden" name="id" value="' . (int)($data1['id'] ?? 0) . '">';
                                        $modal .= '<div class="mb-1"><label class="form-label">ID Pelanggan / User PPPoE username</label><input type="text" name="IDPEL" class="form-control form-control-sm" value="' . $idpelAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">User PPPoE password</label><input type="text" name="PASSWORD" class="form-control form-control-sm" value="' . $passwordAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Name</label><input type="text" name="NAMA" class="form-control form-control-sm" value="' . $namaAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">NIK</label><input type="text" name="NIK" class="form-control form-control-sm" value="' . $nikAttr . '" readonly></div>';
                                        $modal .= '<input type="hidden" name="PEMILIK" value="' . $pemilikAttr . '">';
                                        $modal .= '<div class="mb-1"><label class="form-label">Server Area</label><input type="text" class="form-control form-control-sm" value="' . $brandAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Area</label><input type="text" name="AREA" class="form-control form-control-sm" value="' . $areaAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Package</label><input type="text" name="PAKET" class="form-control form-control-sm" value="' . $paketAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Address</label><input type="text" name="ALAMAT" class="form-control form-control-sm" value="' . $alamatAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Provinsi</label><input type="text" name="provinsi" class="form-control form-control-sm" value="' . $provinsiAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Kabupaten/Kota</label><input type="text" name="kabupaten" class="form-control form-control-sm" value="' . $kabupatenAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Kecamatan</label><input type="text" name="kecamatan" class="form-control form-control-sm" value="' . $kecamatanAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Kelurahan</label><input type="text" name="kelurahan" class="form-control form-control-sm" value="' . $kelurahanAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">RT/RW</label><input type="text" class="form-control form-control-sm" value="' . $rtAttr . '/' . $rwAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">WhatsApp</label><input type="text" name="NOWA" class="form-control form-control-sm" value="' . $nowaAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Email</label><input type="email" name="EMAIL" class="form-control form-control-sm" value="' . $emailAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Coordinates</label><input type="text" name="TIKOR" class="form-control form-control-sm" value="' . $tikorAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">ODP</label><input type="text" name="ODP" class="form-control form-control-sm" value="' . $odpAttr . '" readonly></div>';
                                        $modal .= '<div class="mb-1"><label class="form-label">Sales</label><input type="text" name="sales" class="form-control form-control-sm" value="' . $salesAttr . '" readonly></div>';
                                        $modal .= '</form>';
                                        $modal .= '</div>';

                                        $modal .= '<div class="col-12 col-lg-4 overview-health-col">';
                                        $modal .= '<div class="tiket total overview-created" data-id="' . $idpelAttr . '">Loading...</div>';
                                        $modal .= '<span class="text-secondary text-xs font-weight-bold overview-meta-item">Created : ' . htmlspecialchars(format_tanggal_indo($tanggalPasang, false), ENT_QUOTES, 'UTF-8') . $agingLabel . '</span>';
                                        $modal .= '<p class="text-xs font-weight mb-0 overview-meta-item">Tipe bayar : <span class="badge ' . $tipeBayarBadge . '">' . htmlspecialchars($tipeBayar, ENT_QUOTES, 'UTF-8') . '</span></p>';
                                        $modal .= '<p class="text-xs font-weight mb-0 overview-meta-item">Tipe tempo : <span class="badge ' . $tipeTempoBadge . '">' . htmlspecialchars($tipeTempoLabel, ENT_QUOTES, 'UTF-8') . '</span></p>';
                                        if ($isJatuhTempoLifetime) {
                                            $modal .= '<p class="text-xs font-weight mb-0 overview-meta-item">Jatuh tempo berikutnya : Lifetime</p>';
                                        } elseif ($isJatuhTempoPromo) {
                                            $modal .= '<p class="text-xs font-weight mb-0 overview-meta-item">Jatuh tempo (promo s.d.) : ' . ($jatuhTempoBerikutnya !== '' ? htmlspecialchars(format_tanggal_indo($jatuhTempoBerikutnya, false), ENT_QUOTES, 'UTF-8') : '-') . '</p>';
                                        } elseif ($jatuhTempoBerikutnya !== '') {
                                            $modal .= '<p class="text-xs font-weight mb-0 overview-meta-item">Jatuh tempo berikutnya : ' . htmlspecialchars(format_tanggal_indo($jatuhTempoBerikutnya, false), ENT_QUOTES, 'UTF-8');
                                            $modal .= $editTempoPencilHtml;
                                            $modal .= $jatuhTempoMundurHtml;
                                            $modal .= '</p>';
                                        }
                                        $modal .= '<span style="font-size:15px;" id="data-status-' . $idpelAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                                        $modal .= '<div id="data-paket-aktif-modal-' . $idpelAttr . '" class="overview-meta-item"></div>';
                                        $modal .= '<div id="data-sla-modal-' . $idpelAttr . '" class="overview-meta-item"><span class="badge badge-sm bg-gradient-info">SLA PELANGGAN 0.00%</span></div>';

                                        $modal .= '<div class="d-flex flex-column gap-2 overview-health-stack">';
                                        $modal .= '<span id="data-info-' . $idpelAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';

                                        $modal .= '<div class="sla-history-card" id="slaHistoryWrap-' . $idpelAttr . '">';
                                        $modal .= '<div class="sla-history-title">Riwayat SLA per bulan</div>';
                                        $modal .= '<div id="slaHistoryBody-' . $idpelAttr . '"><span class="text-secondary small">Memuat...</span></div>';
                                        $modal .= '</div>';

                                        $modal .= '<div id="remoteContainerModal-' . $idpelAttr . '"></div>';
                                        if ($hpAttr !== '') {
                                            $modal .= '<a href="https://wa.me/' . $hpAttr . '" target="_blank" class="btn btn-success btn-sm">WhatsApp ' . $hpAttr . '</a>';
                                        }
                                        $modal .= '<button type="button" class="btn btn-primary btn-sm modal-action-btn" data-bs-toggle="modal" data-bs-target="#examplelivechat' . $idpelAttr . '">Live Chat</button>';
                                        $modal .= '<a class="btn btn-secondary btn-sm" href="editcustomerform.php?IDPEL=' . urlencode($idpel) . '">Edit Data</a>';
                                        $modal .= '<button type="button" class="send-invoice btn btn-warning btn-sm modal-action-btn" data-form-id="' . $formId . '">Send Invoice</button>';
                                        $modal .= '<button type="button" class="btn btn-warning btn-sm modal-action-btn" onclick="openCreateInvoiceModal(this)" data-idpel="' . $idpelAttr . '" data-pemilik="' . $pemilikAttr . '" data-nama="' . $namaAttr . '" data-tipe-tempo="' . htmlspecialchars($tipeTempo, ENT_QUOTES, 'UTF-8') . '" data-jatuh-tempo="' . $editTempoDefaultDateAttr . '">Buat Invoice</button>';
                                        $modal .= '<button type="button" class="active-customer btn btn-success btn-sm modal-action-btn" data-form-id="' . $formId . '" data-tipe-tempo="' . htmlspecialchars($tipeTempo, ENT_QUOTES, 'UTF-8') . '">Manual Active</button>';
                                        $modal .= '<button type="button" class="btn btn-info btn-sm modal-action-btn" onclick="openDiscountSettingModal(this)" data-idpel="' . $idpelAttr . '" data-pemilik="' . $pemilikAttr . '" data-nama="' . $namaAttr . '">Setting Diskon</button>';
                                        $modal .= '<button type="button" class="btn btn-dark btn-sm modal-action-btn" onclick="openFeeSettingModal(this)" data-idpel="' . $idpelAttr . '" data-pemilik="' . $pemilikAttr . '" data-nama="' . $namaAttr . '">Tambah Biaya</button>';
                                        $modal .= '<button type="button" class="btn btn-danger btn-sm modal-action-btn" onclick="resetKoneksi(this, \'' . $idpelAttr . '\', \'' . $pemilikAttr . '\', \'' . $areaAttr . '\', \'' . $namaAttr . '\', \'' . $nowaAttr . '\')">Reset Koneksi</button>';
                                        $modal .= '<button type="button" class="btn btn-danger btn-sm modal-action-btn" onclick="resetPemakaian(this, \'' . $idpelAttr . '\', \'' . $namaAttr . '\')">Reset Counter Pemakaian</button>';
                                        if ($AKSES === 'ADMIN') {
                                            $modal .= '<button type="button" class="btn btn-sm btn-warning modal-action-btn" data-perm="btn_cust_buat_tiket" data-idpel="' . $idpelAttr . '" data-brand="' . $brandAttr . '" data-nowa="' . $nowaAttr . '" data-nama="' . $namaAttr . '" data-alamat="' . $alamatAttr . '" data-email="' . $emailAttr . '" data-tikor="' . $tikorAttr . '" data-odp="' . $odpAttr . '" onclick="openModal(this)">Buat Tiket</button>';
                                        }
                                        $modal .= '<a class="btn btn-sm btn-danger" href="proses/deletecustomer.php?hapusid=' . (int)($data1['id'] ?? 0) . '&idpel=' . urlencode($idpel) . '&nowa=' . urlencode((string)($data1['NOWA'] ?? '')) . '&nama=' . urlencode((string)($data1['NAMA'] ?? '')) . '&area=' . urlencode($area) . '&server=' . urlencode($pemilik) . '" onclick="return confirm(\'Apakah Anda yakin ingin menghapus data ini?\')">Dismantle customer / Berhenti langganan</a>';
                                        $modal .= '</div>';
                                        $modal .= '</div>';
                                        $modal .= '</div>';

                                        $modal .= '<div class="acs-device-card border rounded p-3 mt-3" id="acsPanel-' . $idpelAttr . '" data-idpel="' . $idpelAttr . '" data-server-id="">';
                                        $modal .= '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
                                        $modal .= '<div class="acs-section-title fw-bold"><i class="fas fa-wifi"></i> Data ACS</div>';
                                        $modal .= '<span id="acs-sync-info-' . $idpelAttr . '" class="rounded px-2 py-1"></span>';
                                        $modal .= '</div>';
                                        $modal .= '<div id="acsPanelBody-' . $idpelAttr . '"><span class="acs-empty-text">Memuat data ACS...</span></div>';
                                        $modal .= '</div>';

                                        $modal .= '</div>';
                                        $modal .= '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button></div>';
                                        $modal .= '</div></div></div>';

                                        $modal .= '<script>
                                        document.addEventListener("DOMContentLoaded", function() {
                                            var tikor = "' . $tikorAttr . '";
                                            var modalEl = document.getElementById("exampleoverview' . $idpelAttr . '");
                                            if (!modalEl) return;
                                            modalEl.addEventListener("shown.bs.modal", function() {
                                                if (typeof fetchStatusOnDemandIfFastMode === "function") {
                                                    fetchStatusOnDemandIfFastMode("' . $idpelAttr . '");
                                                }
                                                if (typeof loadAcsDevicePanel === "function") {
                                                    loadAcsDevicePanel("' . $idpelAttr . '");
                                                }
                                                if (typeof loadSlaHistory === "function") {
                                                    loadSlaHistory("' . $idpelAttr . '");
                                                }
                                                if (window["_map' . $idpelAttr . '"]) {
                                                    setTimeout(function() { window["_map' . $idpelAttr . '"].invalidateSize(); }, 300);
                                                    return;
                                                }
                                                if (typeof L === "undefined") return;
                                                var map = L.map("map' . $idpelAttr . '").setView([-6.200000, 106.816666], 10);
                                                L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { attribution: "&copy; OpenStreetMap contributors" }).addTo(map);
                                                if (tikor) {
                                                    var coords = tikor.split(",");
                                                    var lat = parseFloat(coords[0]);
                                                    var lng = parseFloat(coords[1] || "");
                                                    if (!isNaN(lat) && !isNaN(lng)) {
                                                        L.marker([lat, lng]).addTo(map).bindPopup("' . $idpelAttr . '").openPopup();
                                                        map.setView([lat, lng], 15);
                                                    }
                                                }
                                                window["_map' . $idpelAttr . '"] = map;
                                                setTimeout(function() { map.invalidateSize(); }, 300);
                                            });
                                        });
                                        </script>';

                                        $adminchatFor = ($AKSES === 'ADMIN') ? 'admin' : $pemilikAttr;
                                        $modal .= '<div class="modal fade" id="examplelivechat' . $idpelAttr . '" tabindex="-1" aria-labelledby="exampleModalLabelChat' . $idpelAttr . '" aria-hidden="true" data-bs-backdrop="false">';
                                        $modal .= '<div class="modal-dialog modal-lg"><div class="modal-content" style="flex-direction:column !important; display:flex !important;">';
                                        $modal .= '<div class="modal-header bg-primary text-white"><h6 class="modal-title fw-bold text-white"><i class="fas fa-comments"></i> Live Chat - ' . $namaAttr . '</h6>';
                                        $modal .= '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>';
                                        $modal .= '<div class="modal-body p-0" style="flex:1;"><iframe id="iframeChat' . $idpelAttr . '" width="100%" height="650px" style="border:none;display:none;"></iframe></div>';
                                        $modal .= '<div class="modal-footer bg-light border-top"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Tutup Chat</button></div>';
                                        $modal .= '</div></div></div>';
                                        $modal .= '<script>
                                        document.addEventListener("DOMContentLoaded", function() {
                                            var chatModal = document.getElementById("examplelivechat' . $idpelAttr . '");
                                            var chatIframe = document.getElementById("iframeChat' . $idpelAttr . '");
                                            if (!chatModal || !chatIframe) return;
                                            chatModal.addEventListener("show.bs.modal", function() {
                                                chatIframe.src = "' . rtrim((string)($config['URL'] ?? ''), '/') . '/crm/chat/index.php?admin=' . rawurlencode($adminchatFor) . '&pelanggan=' . rawurlencode($idpel) . '&nowa=' . rawurlencode((string)($data1['NOWA'] ?? '')) . '";
                                                chatIframe.style.display = "block";
                                            });
                                            chatModal.addEventListener("hidden.bs.modal", function() {
                                                chatIframe.src = "";
                                                chatIframe.style.display = "none";
                                            });
                                        });
                                        </script>';

                                        $modalsBuffer .= $modal;
                                    }
                                    ?>
                                 </tbody>
                            </table>
                            <div id="customerModalsContainer"><?php echo $modalsBuffer; ?></div>
                            <div id="customerLazyMeta" class="d-none" data-page="<?php echo (int)$page; ?>" data-total-pages="<?php echo (int)$total_pages; ?>"></div>
                            <?php if ($displayed_total > 0): ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 my-3" id="pagingControlsWrap">
                                <form method="POST" id="pageSizeForm" class="m-0 d-flex align-items-center gap-2">
                                    <?php foreach ($lazyPostPayload as $key => $value): ?>
                                        <?php if ($key !== 'page_size' && is_scalar($value)): ?>
                                            <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <input type="hidden" name="page" value="1">
                                    <label for="pageSizeSelect" class="small text-muted mb-0">Tampilkan</label>
                                    <select name="page_size" id="pageSizeSelect" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                                        <?php foreach ($pageSizeAllowed as $opt): ?>
                                            <option value="<?= (int)$opt; ?>" <?= $pageSize === $opt ? 'selected' : ''; ?>><?= (int)$opt; ?> / halaman</option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>

                                <?php if ($total_pages > 1): ?>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <form method="POST" id="pagingPrevForm" class="m-0">
                                        <?php foreach ($lazyPostPayload as $key => $value): ?>
                                            <?php if (is_scalar($value)): ?>
                                                <input type="hidden" name="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <button type="submit" name="page" value="<?= (int)max(1, $page - 1); ?>" class="btn btn-outline-secondary btn-sm" <?= $page <= 1 ? 'disabled' : ''; ?>>Prev</button>
                                    </form>

                                    <div class="small text-muted text-center">
                                        Halaman <strong id="currentPageText"><?= (int)$page; ?></strong> dari <strong><?= (int)$total_pages; ?></strong>
                                    </div>

                                    <button type="button" id="nextLazyBtn" class="btn btn-primary btn-sm" <?= $page >= $total_pages ? 'disabled' : ''; ?>>Next</button>
                                    <button type="button" id="loadAllBtn" class="btn btn-outline-primary btn-sm" <?= $page >= $total_pages ? 'disabled' : ''; ?>>Muat Semua</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                            <div class="text-center my-3" id="lazyLoadWrap">
                                <div id="lazyLoadIndicator" class="d-none">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
                                    <span class="ms-2 text-secondary" id="lazyLoadIndicatorText">Memuat data berikutnya...</span>
                                </div>
                            </div>
                            <script>
                            (function() {
                                // Paging manual saja (klik Next / Muat Semua) -- TIDAK ada auto-load
                                // saat scroll, supaya tidak ada data ke-load tanpa diminta eksplisit
                                // (penting untuk akun dengan ribuan pelanggan).
                                let currentPage = <?php echo (int)$page; ?>;
                                const totalPages = <?php echo (int)$total_pages; ?>;
                                let isLoading = false;
                                const basePayload = <?php echo json_encode($lazyPostPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};

                                const tableBody = document.getElementById('customerTableBody');
                                const modalsContainer = document.getElementById('customerModalsContainer');
                                const indicator = document.getElementById('lazyLoadIndicator');
                                const indicatorText = document.getElementById('lazyLoadIndicatorText');
                                const nextBtn = document.getElementById('nextLazyBtn');
                                const loadAllBtn = document.getElementById('loadAllBtn');
                                const currentPageText = document.getElementById('currentPageText');

                                if (!tableBody || !indicator) return;

                                function showIndicator(show, text) {
                                    indicator.classList.toggle('d-none', !show);
                                    if (indicatorText && text) {
                                        indicatorText.textContent = text;
                                    }
                                }

                                function updatePagingUi() {
                                    if (currentPageText) {
                                        currentPageText.textContent = String(currentPage);
                                    }
                                    if (nextBtn) {
                                        nextBtn.disabled = currentPage >= totalPages;
                                    }
                                    if (loadAllBtn) {
                                        loadAllBtn.disabled = currentPage >= totalPages;
                                    }
                                    if (currentPage >= totalPages) {
                                        const wrap = document.getElementById('lazyLoadWrap');
                                        if (wrap) {
                                            wrap.innerHTML = '<span class="text-muted small">Semua data sudah dimuat.</span>';
                                        }
                                    }
                                }

                                function buildLazyPostBody(nextPage) {
                                    const params = new URLSearchParams();
                                    Object.keys(basePayload).forEach(function(key) {
                                        const value = basePayload[key];
                                        if (value !== null && typeof value !== 'object') {
                                            params.append(key, String(value));
                                        }
                                    });
                                    params.set('page', String(nextPage));
                                    return params.toString();
                                }

                                // Re-eksekusi script tags di dalam elemen yang baru di-append
                                // (browser tidak otomatis menjalankan script dari appendChild)
                                function executeScripts(container) {
                                    container.querySelectorAll('script').forEach(function(oldScript) {
                                        const newScript = document.createElement('script');
                                        Array.from(oldScript.attributes).forEach(function(attr) {
                                            newScript.setAttribute(attr.name, attr.value);
                                        });
                                        newScript.textContent = oldScript.textContent;
                                        if (oldScript.parentNode) {
                                            oldScript.parentNode.replaceChild(newScript, oldScript);
                                        } else {
                                            document.body.appendChild(newScript);
                                        }
                                    });
                                }

                                function appendNextPage() {
                                    if (isLoading || currentPage >= totalPages) return Promise.resolve();

                                    isLoading = true;
                                    showIndicator(true, 'Memuat data berikutnya...');
                                    if (nextBtn) nextBtn.disabled = true;
                                    if (loadAllBtn) loadAllBtn.disabled = true;

                                    // Self-POST ke halaman sendiri lalu parse ulang HTML hasilnya
                                    // (tidak ada endpoint/partial terpisah -- logic query & render
                                    // hanya ada satu tempat, yaitu di halaman ini).
                                    return fetch(window.location.href, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                            // Tandai sebagai fetch fragment supaya server TIDAK redirect
                                            // (PRG) - lihat blok redirect di PHP untuk detail alasannya.
                                            'X-Ajax-Fragment': '1'
                                        },
                                        credentials: 'same-origin',
                                        body: buildLazyPostBody(currentPage + 1)
                                    })
                                        .then(function(res) { return res.text(); })
                                        .then(function(html) {
                                            const doc = new DOMParser().parseFromString(html, 'text/html');
                                            const newTableBody = doc.getElementById('customerTableBody');
                                            const newModalsContainer = doc.getElementById('customerModalsContainer');
                                            const newMeta = doc.getElementById('customerLazyMeta');
                                            if (!newTableBody) {
                                                throw new Error('Gagal memuat data');
                                            }

                                            const beforeCount = tableBody.children.length;
                                            tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);
                                            const appendedRows = Array.prototype.slice.call(tableBody.children, beforeCount);

                                            if (modalsContainer && newModalsContainer) {
                                                const modalsBefore = modalsContainer.children.length;
                                                modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                                                const appendedModals = Array.prototype.slice.call(modalsContainer.children, modalsBefore);
                                                appendedModals.forEach(function(modalEl) {
                                                    executeScripts(modalEl);
                                                });
                                            }

                                            // Eksekusi inline scripts (startFetching) pada row yang baru di-append
                                            appendedRows.forEach(function(row) {
                                                executeScripts(row);
                                            });

                                            // Pastikan SLA pada row lazy-load tidak tertinggal 0.00%
                                            // saat data summary SLA sebenarnya sudah tersedia.
                                            if (window.customerSlaSummary && window.customerSlaSummary.customers) {
                                                appendedRows.forEach(function(row) {
                                                    renderSlaBadgesInContainer(row);
                                                });
                                            } else {
                                                loadCustomerSlaSummary().then(function() {
                                                    appendedRows.forEach(function(row) {
                                                        renderSlaBadgesInContainer(row);
                                                    });
                                                });
                                            }

                                            if (typeof updateCustomerRowNumbers === 'function') {
                                                updateCustomerRowNumbers();
                                            }

                                            const parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                                            currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);
                                            updatePagingUi();
                                        })
                                        .catch(function(err) {
                                            console.error('Gagal lazy load:', err);
                                        })
                                        .finally(function() {
                                            isLoading = false;
                                            showIndicator(false);
                                            updatePagingUi();
                                        });
                                }

                                if (nextBtn) {
                                    nextBtn.addEventListener('click', appendNextPage);
                                }

                                // Muat Semua: fetch halaman berikutnya berulang sampai habis (pola yang
                                // sama dengan berhentiLoadAllRemaining/menunggakRevealAllRemaining di
                                // halaman lain), dipicu HANYA lewat klik tombol -- bukan otomatis.
                                function loadAllRemaining() {
                                    if (currentPage >= totalPages) return Promise.resolve();
                                    showIndicator(true, 'Memuat semua data (' + currentPage + ' dari ' + totalPages + ')...');

                                    function step() {
                                        if (currentPage >= totalPages) return Promise.resolve();
                                        return appendNextPage().then(function() {
                                            if (currentPage < totalPages) {
                                                showIndicator(true, 'Memuat semua data (' + currentPage + ' dari ' + totalPages + ')...');
                                            }
                                            return step();
                                        });
                                    }
                                    return step();
                                }

                                if (loadAllBtn) {
                                    loadAllBtn.addEventListener('click', loadAllRemaining);
                                }

                                updatePagingUi();
                            })();
                            </script>
                            <?php endif; ?>


                            <div id="popupModal" class="qts-modal-overlay" style="z-index:9999;">
                                <div class="qts-modal-card" style="width:320px; font-size:14px;">
                                    <style>
                                        @keyframes spin {
                                            0% { transform: rotate(0deg); }
                                            100% { transform: rotate(360deg); }
                                        }
                                    </style>
                                    <div id="loadingOverlay" style="display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); justify-content: center; align-items: center; z-index: 10000;">
                                        <div style="text-align: center;">
                                            <div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 2s linear infinite; margin: 0 auto 10px;"></div>
                                            <p>Loading...</p>
                                        </div>
                                    </div>
                                    <h5>Buat tiket</h5>
                                    <form id="popupForm">
                                        <!-- Hidden values -->
                                        <!-- Hidden input yang diisi otomatis dari tombol -->
                                        <input type="hidden" name="BRAND" id="brandInput">
                                        <input type="hidden" name="IDPEL" id="idpelInput">
                                        <input type="hidden" name="NOWA" id="nowaInput">
                                        <input type="hidden" name="NAMA" id="namaInput">
                                        <input type="hidden" name="ALAMAT" id="alamatInput">
                                        <input type="hidden" name="EMAIL" id="emailInput">
                                        <input type="hidden" name="TIKOR" id="tikorInput">
                                        <input type="hidden" name="ODP" id="odpInput">


                                        <div class="mb-2">
                                            <select name="tipe" class="form-control" required>
                                                <option value="">Pilih Tipe Pekerjaan</option>
                                                <option value="MAINTENANCE">Maintenance</option>
                                                <option value="MIGRASI">Migrasi</option>
                                                <option value="DISMANTLE">Dismantel</option>
                                                <option value="PROVISIONING">Provisioning</option>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <select name="kendala" class="form-control" required>
                                                <option value="">Pilih Kendala</option>
                                                <option value="Tidak ada pembayaran lanjutan">Tidak ada pembayaran lanjutan</option>
                                                <option value="Pindah rumah">Pindah rumah</option>
                                                <option value="Pindah ke provider lain">Pindah ke provider lain</option>
                                                <option value="Tidak Bisa Connect">Tidak Bisa Connect</option>
                                                <option value="WiFi Lemot">WiFi Lemot</option>
                                                <option value="Sinyal Lemah">Sinyal Lemah</option>
                                                <option value="Tidak Ada Internet">Tidak Ada Internet</option>
                                                <option value="Sering Putus">Sering Putus</option>
                                                <option value="Modem Mati">Modem Mati</option>
                                                <option value="Kabel Lepas / Putus">Kabel Lepas / Putus</option>
                                                <option value="Lampu LOS / Merah">Lampu LOS / Merah</option>
                                                <option value="IP Conflict / Tidak Dapat IP">IP Conflict / Tidak Dapat IP</option>
                                                <option value="Lainnya">Lainnya</option>
                                            </select>
                                        </div>


                                        <div class="text-end">
                                            <button type="button" onclick="closeModal()" class="btn btn-secondary btn-sm me-2">Batal</button>
                                            <button type="submit" class="btn btn-primary btn-sm">Kirim</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="manualActiveModal" class="qts-modal-overlay">
                                <div class="qts-modal-card" style="width:360px;">
                                    <h5 class="qts-modal-title">Manual Active</h5>
                                    <p class="mb-2" style="font-size: 12px; color: #6c757d;">
                                        Periode saat ini adalah <strong id="manualCurrentPeriodeText">-</strong>
                                    </p>
                                    <form id="manualActiveForm" enctype="multipart/form-data">
                                        <input type="hidden" id="manualActiveFormId" value="">
                                        <input type="hidden" id="manualTipeTempo" value="">
                                        <div class="mb-2" id="manualMetodeWrap">
                                            <label class="form-label">Metode Pembayaran</label>
                                            <select id="manualMetodeBayar" class="form-control" required>
                                                <option value="cash">Cash</option>
                                                <option value="transfer">Transfer</option>
                                                <option value="Gagal Payment Gateway">Gagal Payment Gateway</option>
                                                <option value="kompensasi_free">Kompensasi Free (Harga 0)</option>
                                            </select>
                                            <small class="text-muted d-block mt-1">Pilih Kompensasi Free untuk aktifkan pelanggan dengan transaksi harga 0 tanpa upload bukti.</small>
                                        </div>
                                        <div class="row" id="manualPeriodeWrap">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Bulan Periode</label>
                                                <select id="manualPeriodeMonth" class="form-control" required>
                                                    <option value="Januari">Januari</option>
                                                    <option value="Februari">Februari</option>
                                                    <option value="Maret">Maret</option>
                                                    <option value="April">April</option>
                                                    <option value="Mei">Mei</option>
                                                    <option value="Juni">Juni</option>
                                                    <option value="Juli">Juli</option>
                                                    <option value="Agustus">Agustus</option>
                                                    <option value="September">September</option>
                                                    <option value="Oktober">Oktober</option>
                                                    <option value="November">November</option>
                                                    <option value="Desember">Desember</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Tahun Periode</label>
                                                <input type="number" id="manualPeriodeYear" class="form-control" min="2000" max="2100" required>
                                            </div>
                                        </div>
                                        <div class="mb-2" id="manualTanggalBayarWrap" style="display:none;">
                                            <label class="form-label">Tanggal Bayar/Aktivasi</label>
                                            <input type="date" id="manualTanggalBayarManual" class="form-control">
                                            <small class="text-muted d-block mt-1">Mode Rolling/Monthversary: jatuh tempo mengikuti tanggal bayar ini, bukan periode kalender.</small>
                                        </div>
                                        <div class="mb-2" id="manualBuktiWrap">
                                            <label class="form-label">Foto Bukti Pembayaran</label>
                                            <input type="file" id="manualBuktiPembayaran" name="bukti_pembayaran" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/*" required>
                                        </div>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="manualOnlyActivate">
                                            <label class="form-check-label" for="manualOnlyActivate">
                                                Hanya aktifkan tanpa update transaksi
                                            </label>
                                        </div>
                                        <div class="text-end">
                                            <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeManualActiveModal()">Batal</button>
                                            <button type="submit" id="manualActiveSubmitBtn" class="btn btn-success btn-sm">Proses Manual Active</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="discountSettingModal" class="qts-modal-overlay">
                                <div class="qts-modal-card">
                                    <h5 class="qts-modal-title">Setting Diskon Pelanggan</h5>
                                    <form id="discountSettingForm">
                                        <input type="hidden" id="discountIdpel" name="idpel">
                                        <input type="hidden" id="discountPemilik" name="pemilik">

                                        <div class="mb-2">
                                            <label class="form-label">Pelanggan</label>
                                            <input type="text" id="discountNamaDisplay" class="form-control" readonly>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Jenis Nilai</label>
                                                <select name="nominal_type" class="form-control" required>
                                                    <option value="nominal">Nominal (Rp)</option>
                                                    <option value="persentase">Persentase (%)</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Nilai Diskon</label>
                                                <input type="number" step="0.01" min="0.01" name="nominal" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Jenis Periode Diskon</label>
                                            <select name="periode_type" id="discountPeriodeType" class="form-control" required>
                                                <option value="bulanan">Satu Bulan Tertentu</option>
                                                <option value="rentang">Rentang Periode (Dari - Sampai)</option>
                                                <option value="permanen">Permanen (Tanpa Batas Waktu)</option>
                                            </select>
                                        </div>

                                        <div class="row" id="discountBulananWrapper">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Bulan Periode</label>
                                                <select name="periode_month" class="form-control">
                                                    <option value="Januari">Januari</option>
                                                    <option value="Februari">Februari</option>
                                                    <option value="Maret">Maret</option>
                                                    <option value="April">April</option>
                                                    <option value="Mei">Mei</option>
                                                    <option value="Juni">Juni</option>
                                                    <option value="Juli">Juli</option>
                                                    <option value="Agustus">Agustus</option>
                                                    <option value="September">September</option>
                                                    <option value="Oktober">Oktober</option>
                                                    <option value="November">November</option>
                                                    <option value="Desember">Desember</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Tahun Periode</label>
                                                <input type="number" min="2000" max="2100" name="periode_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                            </div>
                                        </div>

                                        <div id="discountRentangWrapper" style="display:none;">
                                            <div class="row">
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Dari Bulan</label>
                                                    <select name="periode_start_month" class="form-control">
                                                        <option value="Januari">Januari</option>
                                                        <option value="Februari">Februari</option>
                                                        <option value="Maret">Maret</option>
                                                        <option value="April">April</option>
                                                        <option value="Mei">Mei</option>
                                                        <option value="Juni">Juni</option>
                                                        <option value="Juli">Juli</option>
                                                        <option value="Agustus">Agustus</option>
                                                        <option value="September">September</option>
                                                        <option value="Oktober">Oktober</option>
                                                        <option value="November">November</option>
                                                        <option value="Desember">Desember</option>
                                                    </select>
                                                </div>
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Tahun Mulai</label>
                                                    <input type="number" min="2000" max="2100" name="periode_start_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Sampai Bulan</label>
                                                    <select name="periode_end_month" class="form-control">
                                                        <option value="Januari">Januari</option>
                                                        <option value="Februari">Februari</option>
                                                        <option value="Maret">Maret</option>
                                                        <option value="April">April</option>
                                                        <option value="Mei">Mei</option>
                                                        <option value="Juni">Juni</option>
                                                        <option value="Juli">Juli</option>
                                                        <option value="Agustus">Agustus</option>
                                                        <option value="September">September</option>
                                                        <option value="Oktober">Oktober</option>
                                                        <option value="November">November</option>
                                                        <option value="Desember">Desember</option>
                                                    </select>
                                                </div>
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Tahun Selesai</label>
                                                    <input type="number" min="2000" max="2100" name="periode_end_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div id="discountPermanenInfo" class="alert alert-info py-2 px-3 mb-2" style="display:none; font-size: 12px;">
                                            Diskon akan berlaku terus-menerus setiap periode tagihan sampai dinonaktifkan manual.
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label">Keterangan</label>
                                            <textarea name="keterangan" class="form-control" rows="2" placeholder="Opsional"></textarea>
                                        </div>

                                        <div class="text-end mt-3">
                                            <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeDiscountSettingModal()">Batal</button>
                                            <button type="submit" id="discountSubmitBtn" class="btn btn-info btn-sm">Simpan Diskon</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="feeSettingModal" class="qts-modal-overlay">
                                <div class="qts-modal-card">
                                    <h5 class="qts-modal-title">Tambah Biaya Pelanggan</h5>
                                    <form id="feeSettingForm">
                                        <input type="hidden" id="feeIdpel" name="idpel">
                                        <input type="hidden" id="feePemilik" name="pemilik">

                                        <div class="mb-2">
                                            <label class="form-label">Pelanggan</label>
                                            <input type="text" id="feeNamaDisplay" class="form-control" readonly>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Jenis Nilai</label>
                                                <select name="nominal_type" class="form-control" required>
                                                    <option value="nominal">Nominal (Rp)</option>
                                                    <option value="persentase">Persentase (%)</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Nilai Biaya</label>
                                                <input type="number" step="0.01" min="0.01" name="nominal" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Jenis Periode Biaya</label>
                                            <select name="periode_type" id="feePeriodeType" class="form-control" required>
                                                <option value="bulanan">Satu Bulan Tertentu</option>
                                                <option value="rentang">Rentang Periode (Dari - Sampai)</option>
                                                <option value="permanen">Permanen (Tanpa Batas Waktu)</option>
                                            </select>
                                        </div>

                                        <div class="row" id="feeBulananWrapper">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Bulan Periode</label>
                                                <select name="periode_month" class="form-control">
                                                    <option value="Januari">Januari</option>
                                                    <option value="Februari">Februari</option>
                                                    <option value="Maret">Maret</option>
                                                    <option value="April">April</option>
                                                    <option value="Mei">Mei</option>
                                                    <option value="Juni">Juni</option>
                                                    <option value="Juli">Juli</option>
                                                    <option value="Agustus">Agustus</option>
                                                    <option value="September">September</option>
                                                    <option value="Oktober">Oktober</option>
                                                    <option value="November">November</option>
                                                    <option value="Desember">Desember</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Tahun Periode</label>
                                                <input type="number" min="2000" max="2100" name="periode_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                            </div>
                                        </div>

                                        <div id="feeRentangWrapper" style="display:none;">
                                            <div class="row">
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Dari Bulan</label>
                                                    <select name="periode_start_month" class="form-control">
                                                        <option value="Januari">Januari</option>
                                                        <option value="Februari">Februari</option>
                                                        <option value="Maret">Maret</option>
                                                        <option value="April">April</option>
                                                        <option value="Mei">Mei</option>
                                                        <option value="Juni">Juni</option>
                                                        <option value="Juli">Juli</option>
                                                        <option value="Agustus">Agustus</option>
                                                        <option value="September">September</option>
                                                        <option value="Oktober">Oktober</option>
                                                        <option value="November">November</option>
                                                        <option value="Desember">Desember</option>
                                                    </select>
                                                </div>
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Tahun Mulai</label>
                                                    <input type="number" min="2000" max="2100" name="periode_start_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Sampai Bulan</label>
                                                    <select name="periode_end_month" class="form-control">
                                                        <option value="Januari">Januari</option>
                                                        <option value="Februari">Februari</option>
                                                        <option value="Maret">Maret</option>
                                                        <option value="April">April</option>
                                                        <option value="Mei">Mei</option>
                                                        <option value="Juni">Juni</option>
                                                        <option value="Juli">Juli</option>
                                                        <option value="Agustus">Agustus</option>
                                                        <option value="September">September</option>
                                                        <option value="Oktober">Oktober</option>
                                                        <option value="November">November</option>
                                                        <option value="Desember">Desember</option>
                                                    </select>
                                                </div>
                                                <div class="col-6 mb-2">
                                                    <label class="form-label">Tahun Selesai</label>
                                                    <input type="number" min="2000" max="2100" name="periode_end_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div id="feePermanenInfo" class="alert alert-info py-2 px-3 mb-2" style="display:none; font-size: 12px;">
                                            Biaya tambahan akan berlaku terus-menerus setiap periode tagihan sampai dinonaktifkan manual.
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label">Keterangan</label>
                                            <textarea name="keterangan" class="form-control" rows="2" placeholder="Opsional"></textarea>
                                        </div>

                                        <div class="text-end mt-3">
                                            <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeFeeSettingModal()">Batal</button>
                                            <button type="submit" id="feeSubmitBtn" class="btn btn-dark btn-sm">Simpan Biaya</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="createInvoiceModal" class="qts-modal-overlay">
                                <div class="qts-modal-card">
                                    <h5 class="qts-modal-title">Buat Invoice Penagihan</h5>
                                    <p class="mb-2" style="font-size: 12px; color: #6c757d;">
                                        Invoice hanya dibuat kalau BELUM ada untuk periode yang dipilih (tidak akan menduplikasi invoice/transaksi yang sudah ada).
                                    </p>
                                    <form id="createInvoiceForm">
                                        <input type="hidden" id="createInvoiceIdpel" name="idpel">
                                        <input type="hidden" id="createInvoicePemilik" name="pemilik">
                                        <input type="hidden" id="createInvoiceTipeTempo" value="">

                                        <div class="mb-2">
                                            <label class="form-label">Pelanggan</label>
                                            <input type="text" id="createInvoiceNamaDisplay" class="form-control" readonly>
                                        </div>
                                        <div class="row" id="createInvoicePeriodeWrap">
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Bulan Periode</label>
                                                <select name="periode_month" class="form-control" required>
                                                    <option value="Januari">Januari</option>
                                                    <option value="Februari">Februari</option>
                                                    <option value="Maret">Maret</option>
                                                    <option value="April">April</option>
                                                    <option value="Mei">Mei</option>
                                                    <option value="Juni">Juni</option>
                                                    <option value="Juli">Juli</option>
                                                    <option value="Agustus">Agustus</option>
                                                    <option value="September">September</option>
                                                    <option value="Oktober">Oktober</option>
                                                    <option value="November">November</option>
                                                    <option value="Desember">Desember</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label class="form-label">Tahun Periode</label>
                                                <input type="number" min="2000" max="2100" name="periode_year" class="form-control" value="<?php echo (int)date('Y'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="mb-2" id="createInvoiceTanggalWrap" style="display:none;">
                                            <div class="alert alert-info py-2 px-3 mb-0" style="font-size: 12px;">
                                                Mode Rolling/Monthversary: periode &amp; tanggal jatuh tempo dihitung <b>otomatis</b> oleh sistem sesuai rules pelanggan ini (Rolling: siklus 30 hari; Monthversary: tanggal anchor bulanan) -- tidak perlu diisi manual.
                                            </div>
                                        </div>

                                        <div class="text-end mt-3">
                                            <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeCreateInvoiceModal()">Batal</button>
                                            <button type="submit" id="createInvoiceSubmitBtn" class="btn btn-warning btn-sm">Simpan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="chooseBotSendInvoiceModal" class="qts-modal-overlay">
                                <div class="qts-modal-card">
                                    <h5 class="qts-modal-title">Pilih Bot Pengirim Invoice</h5>
                                    <p class="mb-2" style="font-size: 12px; color: #6c757d;">
                                        Pilih bot WhatsApp yang dipakai untuk mengirim notifikasi invoice ke pelanggan ini.
                                    </p>
                                    <form id="chooseBotSendInvoiceForm">
                                        <div class="mb-2">
                                            <label class="form-label">Bot WhatsApp</label>
                                            <select id="chooseBotSendInvoiceSelect" class="form-control" required>
                                                <option value="">-- Pilih Bot --</option>
                                                <?php
                                                $sendInvoiceBotStmt = $conn->prepare("SELECT id, namebot FROM botwa WHERE pemilik = ?" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
                                                if ($sendInvoiceBotStmt) {
                                                    $sendInvoiceBotStmt->bind_param('s', $ceknama);
                                                    $sendInvoiceBotStmt->execute();
                                                    $sendInvoiceBotResult = $sendInvoiceBotStmt->get_result();
                                                    while ($sendInvoiceBotRow = $sendInvoiceBotResult->fetch_assoc()) {
                                                        echo '<option value="' . (int)$sendInvoiceBotRow['id'] . '">' . htmlspecialchars($sendInvoiceBotRow['namebot']) . '</option>';
                                                    }
                                                    $sendInvoiceBotStmt->close();
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <div class="text-end mt-3">
                                            <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeChooseBotSendInvoiceModal()">Batal</button>
                                            <button type="submit" id="chooseBotSendInvoiceSubmitBtn" class="btn btn-warning btn-sm">Kirim Invoice</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div id="acsSsidEditorModal" class="qts-modal-overlay">
                                <div class="qts-modal-card acs-ssid-editor-dialog">
                                    <div class="acs-ssid-editor-content">
                                        <div class="acs-ssid-editor-body">
                                            <h5 class="qts-modal-title">Edit SSID</h5>
                                            <form id="acsSsidEditorForm">
                                                <input type="hidden" name="idpel" id="acsSsidIdpel">
                                                <input type="hidden" name="server_id" id="acsSsidServerId">
                                                <input type="hidden" name="serial" id="acsSsidSerial">
                                                <input type="hidden" name="ssid_param" id="acsSsidParam">
                                                <input type="hidden" name="ssid_pass_param" id="acsSsidPassParam">
                                                <input type="hidden" name="ssid_enable_param" id="acsSsidEnableParam">
                                                <div class="mb-2">
                                                    <label class="form-label">Nama SSID</label>
                                                    <input type="text" name="ssid" id="acsSsidName" class="form-control" maxlength="32" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label">Password WiFi</label>
                                                    <input type="text" name="ssid_password" id="acsSsidPassword" class="form-control" minlength="8" maxlength="63" placeholder="Kosongkan jika tidak diubah">
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input type="checkbox" class="form-check-input" name="ssid_enable" id="acsSsidEnable">
                                                    <label class="form-check-label" for="acsSsidEnable">Aktifkan SSID</label>
                                                </div>
                                                <div id="acsSsidEditorMsg" class="small mb-2"></div>
                                                <div class="text-end">
                                                    <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeSsidEditor()">Batal</button>
                                                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="acsWanEditorModal" class="qts-modal-overlay">
                                <div class="qts-modal-card acs-wan-editor-dialog">
                                    <div class="acs-wan-editor-content">
                                        <div class="acs-wan-editor-body">
                                            <h5 class="qts-modal-title">Edit WAN Connection</h5>
                                            <form id="acsWanEditorForm">
                                                <input type="hidden" name="idpel" id="acsWanEditIdpel">
                                                <input type="hidden" name="server_id" id="acsWanEditServerId">
                                                <input type="hidden" name="serial" id="acsWanEditSerial">
                                                <input type="hidden" name="wan_id" id="acsWanEditWanId">
                                                <div id="acsWanEditorFields" class="acs-wan-form-grid"></div>
                                                <div id="acsWanEditorMsg" class="small mb-2 mt-2"></div>
                                                <div class="text-end mt-2">
                                                    <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeWanEditor()">Batal</button>
                                                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="acsWanAddModal" class="qts-modal-overlay">
                                <div class="qts-modal-card acs-wan-editor-dialog">
                                    <div class="acs-wan-editor-content">
                                        <div class="acs-wan-editor-body">
                                            <h5 class="qts-modal-title">Tambah WAN Connection</h5>
                                            <form id="acsWanAddForm">
                                                <input type="hidden" name="idpel" id="acsWanAddIdpel">
                                                <input type="hidden" name="server_id" id="acsWanAddServerId">
                                                <input type="hidden" name="serial" id="acsWanAddSerial">
                                                <div class="acs-wan-form-grid">
                                                    <div class="acs-wan-form-field">
                                                        <label class="form-label">Tipe Koneksi</label>
                                                        <select name="connection_type" class="form-control">
                                                            <option value="PPPoE">PPPoE</option>
                                                            <option value="IP">IP (DHCP/Static)</option>
                                                        </select>
                                                    </div>
                                                    <div class="acs-wan-form-field">
                                                        <label class="form-label">Username (PPPoE)</label>
                                                        <input type="text" name="username" class="form-control">
                                                    </div>
                                                    <div class="acs-wan-form-field">
                                                        <label class="form-label">Password (PPPoE)</label>
                                                        <input type="text" name="password" class="form-control">
                                                    </div>
                                                    <div class="acs-wan-form-field acs-wan-form-field-full">
                                                        <label class="form-label">VLAN ID (opsional)</label>
                                                        <input type="number" name="vlan_id" class="form-control" min="0" max="4094">
                                                    </div>
                                                </div>
                                                <div id="acsWanAddMsg" class="small mb-2 mt-2"></div>
                                                <div class="text-end mt-2">
                                                    <button type="button" class="btn btn-secondary btn-sm me-2" onclick="closeWanAddDialog()">Batal</button>
                                                    <button type="submit" class="btn btn-success btn-sm">Tambah</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>


     <script>
                                let currentButton = null;

                                // Sembunyikan sementara modal Bootstrap yang lagi terbuka (mis. Overview
                                // Customer data) begitu modal kecil (.qts-modal-overlay) ditampilkan di
                                // atasnya, lalu kembalikan saat modal kecil ditutup - supaya modalnya tidak
                                // numpuk dua-duanya sekaligus (sumber potensi bug render/select tertutup).
                                // Pola yang sama dipakai di openDiscountSettingModal/openFeeSettingModal.
                                function hideUnderlyingBootstrapModals(backrefKey) {
                                    const openModals = document.querySelectorAll('.modal.show');
                                    window[backrefKey] = {
                                        modals: Array.from(openModals),
                                        backdrop: document.querySelector('.modal-backdrop')
                                    };
                                    openModals.forEach(modal => {
                                        modal.style.display = 'none';
                                        modal.classList.remove('show');
                                    });
                                }

                                function restoreUnderlyingBootstrapModals(backrefKey) {
                                    const ref = window[backrefKey];
                                    if (!ref) return;
                                    if (ref.backdrop) {
                                        ref.backdrop.style.display = 'block';
                                        ref.backdrop.classList.add('show');
                                    }
                                    ref.modals.forEach(modal => {
                                        modal.style.display = 'block';
                                        modal.classList.add('show');
                                    });
                                    window[backrefKey] = null;
                                }

                                function openModal(button) {
                                    currentButton = button;
                                    hideUnderlyingBootstrapModals('_popupModalBackref');

                                    // Ambil data dari tombol
                                    const idpel = button.getAttribute("data-idpel") || "";
                                     const brand = button.getAttribute("data-brand") || "";
                                    const nowa = button.getAttribute("data-nowa") || "";
                                    const nama = button.getAttribute("data-nama") || "";
                                    const alamat = button.getAttribute("data-alamat") || "";
                                    const email = button.getAttribute("data-email") || "";
                                    const tikor = button.getAttribute("data-tikor") || "";
                                    const odp = button.getAttribute("data-odp") || "";

                                    // Isi input hidden di form modal
                                    document.getElementById("idpelInput").value = idpel;
                                    document.getElementById("brandInput").value = brand;
                                    document.getElementById("nowaInput").value = nowa;
                                    document.getElementById("namaInput").value = nama;
                                    document.getElementById("alamatInput").value = alamat;
                                    document.getElementById("emailInput").value = email;
                                    document.getElementById("tikorInput").value = tikor;
                                    document.getElementById("odpInput").value = odp;

                                    // Debug log (boleh dihapus setelah selesai)
                                    console.log("Modal Data:", {
                                        idpel,
                                        brand,
                                        nowa,
                                        nama,
                                        alamat,
                                        email,
                                        tikor,
                                        odp
                                    });

                                    // Tampilkan modal
                                    document.getElementById("popupModal").style.display = "flex";
                                    // Pastikan loading disembunyikan
                                    document.getElementById("loadingOverlay").style.display = "none";
                                    // Enable form elements
                                    const formElements = document.getElementById("popupForm").querySelectorAll("input, select, button");
                                    formElements.forEach(el => el.disabled = false);
                                }

                                function closeModal() {
                                    document.getElementById("popupModal").style.display = "none";
                                    document.getElementById("popupForm").reset();
                                    // Sembunyikan loading jika masih terlihat
                                    document.getElementById("loadingOverlay").style.display = "none";
                                    // Enable form elements
                                    const formElements = document.getElementById("popupForm").querySelectorAll("input, select, button");
                                    formElements.forEach(el => el.disabled = false);
                                    restoreUnderlyingBootstrapModals('_popupModalBackref');
                                }

                                document.getElementById("popupForm").addEventListener("submit", function(e) {
                                    e.preventDefault();
                                    const formData = new FormData(this);

                                    // Tampilkan loading
                                    document.getElementById("loadingOverlay").style.display = "flex";
                                    // Disable form elements
                                    const formElements = this.querySelectorAll("input, select, button");
                                    formElements.forEach(el => el.disabled = true);

                                    fetch("buat_tiket.php", {
                                            method: "POST",
                                            body: formData
                                        })
                                        .then(res => res.json())
                                        .then(data => {
                                            // Sembunyikan loading
                                            document.getElementById("loadingOverlay").style.display = "none";
                                            // Enable form elements
                                            formElements.forEach(el => el.disabled = false);

                                            alert(data.message);
                                            if (data.success && currentButton) {
                                                currentButton.disabled = true;
                                                currentButton.innerText = "✔ Tiket Terkirim";
                                            }
                                            closeModal();
                                        })
                                        .catch(() => {
                                            // Sembunyikan loading
                                            document.getElementById("loadingOverlay").style.display = "none";
                                            // Enable form elements
                                            formElements.forEach(el => el.disabled = false);

                                            alert("Gagal mengirim tiket.");
                                        });
                                });
                            </script>


                            <script>
                                function handleFormAction(selector, url, confirmationMessageFn) {
                                    document.querySelectorAll(selector).forEach(button => {
                                        button.addEventListener("click", function() {
                                            const formId = this.getAttribute("data-form-id");
                                            const form = document.getElementById(formId);
                                            if (!form) {
                                                console.error(`Form dengan ID '${formId}' tidak ditemukan.`);
                                                return;
                                            }

                                            const formData = new FormData(form);


                                            const confirmationMessage = confirmationMessageFn(formId);
                                            if (confirm(confirmationMessage)) {
                                                fetch(url, {
                                                        method: "POST",
                                                        body: formData
                                                    })
                                                    .then(response => response.json())
                                                    .then(data => {
                                                        // Selalu tampilkan di console
                                                        console.log("[disablecustomer response]", data);
                                                        // Tampilkan alert hasil response
                                                        alert(data.message || "No response message.");
                                                        // responseBox dihapus, hanya alert dan console.log
                                                    })
                                                    .catch(error => {
                                                        console.error("Error:", error);
                                                        // responseBox dihapus, hanya console.error
                                                    });
                                            }
                                        });
                                    });
                                }

                                // Send Invoice: tampilkan modal pilih bot dulu, bukan langsung confirm().
                                let pendingSendInvoiceFormId = null;

                                function openChooseBotSendInvoiceModal(formId) {
                                    pendingSendInvoiceFormId = formId;

                                    const openModals = document.querySelectorAll('.modal.show');
                                    window._chooseBotSendInvoiceModalBackref = {
                                        modals: Array.from(openModals),
                                        backdrop: document.querySelector('.modal-backdrop')
                                    };
                                    openModals.forEach(modal => {
                                        modal.style.display = 'none';
                                        modal.classList.remove('show');
                                    });
                                    const backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) {
                                        backdrop.style.display = 'none';
                                        backdrop.classList.remove('show');
                                    }

                                    document.getElementById('chooseBotSendInvoiceForm').reset();
                                    document.getElementById('chooseBotSendInvoiceModal').style.display = 'flex';
                                    document.body.classList.add('overflow-hidden');
                                }

                                function closeChooseBotSendInvoiceModal() {
                                    pendingSendInvoiceFormId = null;
                                    document.getElementById('chooseBotSendInvoiceModal').style.display = 'none';
                                    document.body.classList.remove('overflow-hidden');

                                    if (window._chooseBotSendInvoiceModalBackref) {
                                        if (window._chooseBotSendInvoiceModalBackref.backdrop) {
                                            window._chooseBotSendInvoiceModalBackref.backdrop.style.display = 'block';
                                            window._chooseBotSendInvoiceModalBackref.backdrop.classList.add('show');
                                        }
                                        window._chooseBotSendInvoiceModalBackref.modals.forEach(modal => {
                                            modal.style.display = 'block';
                                            modal.classList.add('show');
                                        });
                                    }
                                }

                                document.querySelectorAll(".send-invoice").forEach(button => {
                                    button.addEventListener("click", function() {
                                        const formId = this.getAttribute("data-form-id");
                                        if (!document.getElementById(formId)) {
                                            console.error(`Form dengan ID '${formId}' tidak ditemukan.`);
                                            return;
                                        }
                                        openChooseBotSendInvoiceModal(formId);
                                    });
                                });

                                document.getElementById('chooseBotSendInvoiceForm').addEventListener('submit', function(e) {
                                    e.preventDefault();

                                    const form = document.getElementById(pendingSendInvoiceFormId);
                                    const botSelect = document.getElementById('chooseBotSendInvoiceSelect');
                                    if (!form || !botSelect.value) {
                                        alert('Pilih bot pengirim terlebih dahulu.');
                                        return;
                                    }

                                    const formData = new FormData(form);
                                    formData.append('bot_id', botSelect.value);

                                    const submitBtn = document.getElementById('chooseBotSendInvoiceSubmitBtn');
                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Mengirim...';

                                    fetch('proses/sendinvoice.php', {
                                            method: 'POST',
                                            body: formData
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            console.log('[sendinvoice response]', data);
                                            alert(data.message || 'No response message.');
                                            closeChooseBotSendInvoiceModal();
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            alert('Gagal mengirim invoice.');
                                        })
                                        .finally(() => {
                                            submitBtn.disabled = false;
                                            submitBtn.textContent = 'Kirim Invoice';
                                        });
                                });

                                function toggleManualOnlyActivateFields() {
                                    const onlyActivate = document.getElementById('manualOnlyActivate').checked;
                                    const metodeBayar = (document.getElementById('manualMetodeBayar').value || '').toLowerCase();
                                    const isKompensasiFree = metodeBayar === 'kompensasi_free';
                                    const tipeTempo = document.getElementById('manualTipeTempo').value || '';
                                    const isRollingOrMv = (tipeTempo === 'mengikuti_tanggal_bayar' || tipeTempo === 'monthversary');
                                    const metodeWrap = document.getElementById('manualMetodeWrap');
                                    const periodeWrap = document.getElementById('manualPeriodeWrap');
                                    const tanggalBayarWrap = document.getElementById('manualTanggalBayarWrap');
                                    const buktiWrap = document.getElementById('manualBuktiWrap');

                                    const metodeInput = document.getElementById('manualMetodeBayar');
                                    const periodeMonthInput = document.getElementById('manualPeriodeMonth');
                                    const periodeYearInput = document.getElementById('manualPeriodeYear');
                                    const tanggalBayarInput = document.getElementById('manualTanggalBayarManual');
                                    const buktiInput = document.getElementById('manualBuktiPembayaran');

                                    metodeWrap.style.display = onlyActivate ? 'none' : 'block';
                                    periodeWrap.style.display = (onlyActivate || isRollingOrMv) ? 'none' : 'flex';
                                    tanggalBayarWrap.style.display = (onlyActivate || !isRollingOrMv) ? 'none' : 'block';
                                    buktiWrap.style.display = (onlyActivate || isKompensasiFree) ? 'none' : 'block';

                                    // Hindari validasi HTML5 pada field yang disembunyikan.
                                    metodeInput.disabled = onlyActivate;
                                    periodeMonthInput.disabled = onlyActivate || isRollingOrMv;
                                    periodeYearInput.disabled = onlyActivate || isRollingOrMv;
                                    tanggalBayarInput.disabled = onlyActivate || !isRollingOrMv;
                                    buktiInput.disabled = (onlyActivate || isKompensasiFree);

                                    metodeInput.required = !onlyActivate;
                                    periodeMonthInput.required = !onlyActivate && !isRollingOrMv;
                                    periodeYearInput.required = !onlyActivate && !isRollingOrMv;
                                    tanggalBayarInput.required = !onlyActivate && isRollingOrMv;
                                    buktiInput.required = !(onlyActivate || isKompensasiFree);

                                    if (onlyActivate || isKompensasiFree) {
                                        buktiInput.value = '';
                                    }
                                }

                                function openManualActiveModal(formId, tipeTempo) {
                                    const now = new Date();
                                    const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                    const currentPeriode = monthNames[now.getMonth()] + ' ' + now.getFullYear();
                                    const todayYmd = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');

                                    document.getElementById('manualActiveFormId').value = formId;
                                    document.getElementById('manualTipeTempo').value = tipeTempo || '';
                                    document.getElementById('manualMetodeBayar').value = 'cash';
                                    document.getElementById('manualPeriodeMonth').value = monthNames[now.getMonth()];
                                    document.getElementById('manualPeriodeYear').value = now.getFullYear();
                                    document.getElementById('manualTanggalBayarManual').value = todayYmd;
                                    document.getElementById('manualCurrentPeriodeText').textContent = currentPeriode;
                                    document.getElementById('manualBuktiPembayaran').value = '';
                                    document.getElementById('manualOnlyActivate').checked = false;
                                    toggleManualOnlyActivateFields();
                                    hideUnderlyingBootstrapModals('_manualActiveModalBackref');
                                    document.getElementById('manualActiveModal').style.display = 'flex';
                                }

                                function closeManualActiveModal() {
                                    document.getElementById('manualActiveModal').style.display = 'none';
                                    restoreUnderlyingBootstrapModals('_manualActiveModalBackref');
                                }

                                document.querySelectorAll('.active-customer').forEach(button => {
                                    button.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        const formId = this.getAttribute('data-form-id');
                                        const tipeTempo = this.getAttribute('data-tipe-tempo') || '';
                                        openManualActiveModal(formId, tipeTempo);
                                    });
                                });

                                document.getElementById('manualOnlyActivate').addEventListener('change', toggleManualOnlyActivateFields);
                                document.getElementById('manualMetodeBayar').addEventListener('change', toggleManualOnlyActivateFields);

                                document.getElementById('manualActiveForm').addEventListener('submit', function(e) {
                                    e.preventDefault();

                                    const formId = document.getElementById('manualActiveFormId').value;
                                    const tipeTempo = document.getElementById('manualTipeTempo').value || '';
                                    const isRollingOrMv = (tipeTempo === 'mengikuti_tanggal_bayar' || tipeTempo === 'monthversary');
                                    const metodeBayar = document.getElementById('manualMetodeBayar').value;
                                    const periodeMonth = document.getElementById('manualPeriodeMonth').value;
                                    const periodeYear = document.getElementById('manualPeriodeYear').value;
                                    const tanggalBayarManual = document.getElementById('manualTanggalBayarManual').value;
                                    const onlyActivate = document.getElementById('manualOnlyActivate').checked;
                                    const isKompensasiFree = (metodeBayar || '').toLowerCase() === 'kompensasi_free';
                                    const buktiInput = document.getElementById('manualBuktiPembayaran');
                                    const buktiFile = buktiInput.files[0] || null;
                                    const submitBtn = document.getElementById('manualActiveSubmitBtn');

                                    const form = document.getElementById(formId);
                                    if (!form) {
                                        alert('Form customer tidak ditemukan.');
                                        return;
                                    }

                                    if (!onlyActivate && isRollingOrMv && !tanggalBayarManual) {
                                        alert('Tanggal bayar/aktivasi wajib diisi.');
                                        return;
                                    }

                                    if (!onlyActivate && !isKompensasiFree && !buktiFile) {
                                        alert('Foto bukti pembayaran wajib diupload.');
                                        return;
                                    }

                                    const formData = new FormData(form);
                                    formData.append('metode_bayar', metodeBayar);
                                    if (!onlyActivate && isRollingOrMv) {
                                        formData.append('tanggal_bayar_manual', tanggalBayarManual);
                                    } else {
                                        formData.append('periode_month', periodeMonth);
                                        formData.append('periode_year', periodeYear);
                                    }
                                    formData.append('only_activate_without_transaksi', onlyActivate ? '1' : '0');
                                    if (buktiFile && !isKompensasiFree) {
                                        formData.set('bukti_pembayaran', buktiFile, buktiFile.name || 'bukti-pembayaran.jpg');
                                    }

                                    const modeLabel = onlyActivate
                                        ? 'only activate (no transaction update)'
                                        : (isRollingOrMv ? `tanggal [${tanggalBayarManual}]` : `period [${periodeMonth} ${periodeYear}]`);
                                    if (!confirm(`Are you sure you want to manually activate customer [${formId}] with payment method [${metodeBayar}] for ${modeLabel}?`)) {
                                        return;
                                    }

                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Memproses...';

                                    fetch('proses/activecustomer.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        alert(data.message || 'No response message.');
                                        closeManualActiveModal();
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Gagal memproses manual active.');
                                    })
                                    .finally(() => {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = 'Proses Manual Active';
                                    });
                                });

                                function setCurrentPeriodeToForm(formId) {
                                    const form = document.getElementById(formId);
                                    if (!form) return;

                                    const now = new Date();
                                    const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                    const monthSelect = form.querySelector('[name="periode_month"]');
                                    const yearInput = form.querySelector('[name="periode_year"]');
                                    const startMonthSelect = form.querySelector('[name="periode_start_month"]');
                                    const startYearInput = form.querySelector('[name="periode_start_year"]');
                                    const endMonthSelect = form.querySelector('[name="periode_end_month"]');
                                    const endYearInput = form.querySelector('[name="periode_end_year"]');

                                    if (monthSelect) monthSelect.value = monthNames[now.getMonth()];
                                    if (yearInput) yearInput.value = now.getFullYear();
                                    if (startMonthSelect) startMonthSelect.value = monthNames[now.getMonth()];
                                    if (startYearInput) startYearInput.value = now.getFullYear();
                                    if (endMonthSelect) endMonthSelect.value = monthNames[now.getMonth()];
                                    if (endYearInput) endYearInput.value = now.getFullYear();
                                }

                                function toggleDiscountPeriodeType() {
                                    const typeEl = document.getElementById('discountPeriodeType');
                                    if (!typeEl) return;
                                    const type = typeEl.value;

                                    const bulananWrapper = document.getElementById('discountBulananWrapper');
                                    const rentangWrapper = document.getElementById('discountRentangWrapper');
                                    const permanenInfo = document.getElementById('discountPermanenInfo');
                                    const form = document.getElementById('discountSettingForm');

                                    bulananWrapper.style.display = (type === 'bulanan') ? '' : 'none';
                                    rentangWrapper.style.display = (type === 'rentang') ? '' : 'none';
                                    permanenInfo.style.display = (type === 'permanen') ? '' : 'none';

                                    const bulanField = form.querySelector('[name="periode_month"]');
                                    const tahunField = form.querySelector('[name="periode_year"]');
                                    if (bulanField) bulanField.required = (type === 'bulanan');
                                    if (tahunField) tahunField.required = (type === 'bulanan');

                                    const startMonthField = form.querySelector('[name="periode_start_month"]');
                                    const startYearField = form.querySelector('[name="periode_start_year"]');
                                    const endMonthField = form.querySelector('[name="periode_end_month"]');
                                    const endYearField = form.querySelector('[name="periode_end_year"]');
                                    if (startMonthField) startMonthField.required = (type === 'rentang');
                                    if (startYearField) startYearField.required = (type === 'rentang');
                                    if (endMonthField) endMonthField.required = (type === 'rentang');
                                    if (endYearField) endYearField.required = (type === 'rentang');
                                }
                                document.getElementById('discountPeriodeType').addEventListener('change', toggleDiscountPeriodeType);

                                function openDiscountSettingModal(button) {
                                    const idpel = button.getAttribute('data-idpel') || '';
                                    const pemilik = button.getAttribute('data-pemilik') || '';
                                    const nama = button.getAttribute('data-nama') || '';

                                    // Simpan referensi modal Bootstrap yang sedang terbuka
                                    const openModals = document.querySelectorAll('.modal.show');
                                    window._discountModalBackref = {
                                        modals: Array.from(openModals),
                                        backdrop: document.querySelector('.modal-backdrop')
                                    };

                                    // Sembunyikan semua Bootstrap modal dan backdrop
                                    openModals.forEach(modal => {
                                        modal.style.display = 'none';
                                        modal.classList.remove('show');
                                    });
                                    const backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) {
                                        backdrop.style.display = 'none';
                                        backdrop.classList.remove('show');
                                    }

                                    const form = document.getElementById('discountSettingForm');
                                    form.reset();
                                    setCurrentPeriodeToForm('discountSettingForm');
                                    form.querySelector('[name="nominal_type"]').value = 'nominal';
                                    document.getElementById('discountPeriodeType').value = 'bulanan';
                                    toggleDiscountPeriodeType();

                                    document.getElementById('discountIdpel').value = idpel;
                                    document.getElementById('discountPemilik').value = pemilik;
                                    document.getElementById('discountNamaDisplay').value = idpel + ' - ' + nama;

                                    const nominalInput = form.querySelector('[name="nominal"]');
                                    const keteranganInput = form.querySelector('[name="keterangan"]');
                                    
                                    if (nominalInput) {
                                        nominalInput.disabled = false;
                                        nominalInput.readOnly = false;
                                        nominalInput.value = '';
                                        nominalInput.removeAttribute('disabled');
                                        nominalInput.removeAttribute('readonly');
                                    }
                                    if (keteranganInput) {
                                        keteranganInput.disabled = false;
                                        keteranganInput.readOnly = false;
                                        keteranganInput.value = '';
                                        keteranganInput.removeAttribute('disabled');
                                        keteranganInput.removeAttribute('readonly');
                                    }

                                    document.getElementById('discountSettingModal').style.display = 'flex';
                                    if (nominalInput) {
                                        setTimeout(() => nominalInput.focus(), 80);
                                    }
                                    document.body.classList.add('overflow-hidden');
                                }

                                function closeDiscountSettingModal() {
                                    document.getElementById('discountSettingModal').style.display = 'none';
                                    document.body.classList.remove('overflow-hidden');

                                    // Restore modal Bootstrap dan backdrop
                                    if (window._discountModalBackref) {
                                        if (window._discountModalBackref.backdrop) {
                                            window._discountModalBackref.backdrop.style.display = 'block';
                                            window._discountModalBackref.backdrop.classList.add('show');
                                        }
                                        window._discountModalBackref.modals.forEach(modal => {
                                            modal.style.display = 'block';
                                            modal.classList.add('show');
                                        });
                                    }
                                }

                                function toggleFeePeriodeType() {
                                    const typeEl = document.getElementById('feePeriodeType');
                                    if (!typeEl) return;
                                    const type = typeEl.value;

                                    const bulananWrapper = document.getElementById('feeBulananWrapper');
                                    const rentangWrapper = document.getElementById('feeRentangWrapper');
                                    const permanenInfo = document.getElementById('feePermanenInfo');
                                    const form = document.getElementById('feeSettingForm');

                                    bulananWrapper.style.display = (type === 'bulanan') ? '' : 'none';
                                    rentangWrapper.style.display = (type === 'rentang') ? '' : 'none';
                                    permanenInfo.style.display = (type === 'permanen') ? '' : 'none';

                                    const bulanField = form.querySelector('[name="periode_month"]');
                                    const tahunField = form.querySelector('[name="periode_year"]');
                                    if (bulanField) bulanField.required = (type === 'bulanan');
                                    if (tahunField) tahunField.required = (type === 'bulanan');

                                    const startMonthField = form.querySelector('[name="periode_start_month"]');
                                    const startYearField = form.querySelector('[name="periode_start_year"]');
                                    const endMonthField = form.querySelector('[name="periode_end_month"]');
                                    const endYearField = form.querySelector('[name="periode_end_year"]');
                                    if (startMonthField) startMonthField.required = (type === 'rentang');
                                    if (startYearField) startYearField.required = (type === 'rentang');
                                    if (endMonthField) endMonthField.required = (type === 'rentang');
                                    if (endYearField) endYearField.required = (type === 'rentang');
                                }
                                document.getElementById('feePeriodeType').addEventListener('change', toggleFeePeriodeType);

                                function openFeeSettingModal(button) {
                                    const idpel = button.getAttribute('data-idpel') || '';
                                    const pemilik = button.getAttribute('data-pemilik') || '';
                                    const nama = button.getAttribute('data-nama') || '';

                                    // Simpan referensi modal Bootstrap yang sedang terbuka
                                    const openModals = document.querySelectorAll('.modal.show');
                                    window._feeModalBackref = {
                                        modals: Array.from(openModals),
                                        backdrop: document.querySelector('.modal-backdrop')
                                    };

                                    // Sembunyikan semua Bootstrap modal dan backdrop
                                    openModals.forEach(modal => {
                                        modal.style.display = 'none';
                                        modal.classList.remove('show');
                                    });
                                    const backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) {
                                        backdrop.style.display = 'none';
                                        backdrop.classList.remove('show');
                                    }

                                    const form = document.getElementById('feeSettingForm');
                                    form.reset();
                                    setCurrentPeriodeToForm('feeSettingForm');
                                    form.querySelector('[name="nominal_type"]').value = 'nominal';
                                    document.getElementById('feePeriodeType').value = 'bulanan';
                                    toggleFeePeriodeType();

                                    document.getElementById('feeIdpel').value = idpel;
                                    document.getElementById('feePemilik').value = pemilik;
                                    document.getElementById('feeNamaDisplay').value = idpel + ' - ' + nama;

                                    const nominalInput = form.querySelector('[name="nominal"]');
                                    const keteranganInput = form.querySelector('[name="keterangan"]');
                                    
                                    if (nominalInput) {
                                        nominalInput.disabled = false;
                                        nominalInput.readOnly = false;
                                        nominalInput.value = '';
                                        nominalInput.removeAttribute('disabled');
                                        nominalInput.removeAttribute('readonly');
                                    }
                                    if (keteranganInput) {
                                        keteranganInput.disabled = false;
                                        keteranganInput.readOnly = false;
                                        keteranganInput.value = '';
                                        keteranganInput.removeAttribute('disabled');
                                        keteranganInput.removeAttribute('readonly');
                                    }

                                    document.getElementById('feeSettingModal').style.display = 'flex';
                                    if (nominalInput) {
                                        setTimeout(() => nominalInput.focus(), 80);
                                    }
                                    document.body.classList.add('overflow-hidden');
                                }

                                function closeFeeSettingModal() {
                                    document.getElementById('feeSettingModal').style.display = 'none';
                                    document.body.classList.remove('overflow-hidden');

                                    // Restore modal Bootstrap dan backdrop
                                    if (window._feeModalBackref) {
                                        if (window._feeModalBackref.backdrop) {
                                            window._feeModalBackref.backdrop.style.display = 'block';
                                            window._feeModalBackref.backdrop.classList.add('show');
                                        }
                                        window._feeModalBackref.modals.forEach(modal => {
                                            modal.style.display = 'block';
                                            modal.classList.add('show');
                                        });
                                    }
                                }

                                function openCreateInvoiceModal(button) {
                                    const idpel = button.getAttribute('data-idpel') || '';
                                    const pemilik = button.getAttribute('data-pemilik') || '';
                                    const nama = button.getAttribute('data-nama') || '';
                                    const tipeTempo = button.getAttribute('data-tipe-tempo') || '';
                                    const isRollingOrMv = (tipeTempo === 'mengikuti_tanggal_bayar' || tipeTempo === 'monthversary');

                                    // Simpan referensi modal Bootstrap yang sedang terbuka
                                    const openModals = document.querySelectorAll('.modal.show');
                                    window._createInvoiceModalBackref = {
                                        modals: Array.from(openModals),
                                        backdrop: document.querySelector('.modal-backdrop')
                                    };

                                    // Sembunyikan semua Bootstrap modal dan backdrop
                                    openModals.forEach(modal => {
                                        modal.style.display = 'none';
                                        modal.classList.remove('show');
                                    });
                                    const backdrop = document.querySelector('.modal-backdrop');
                                    if (backdrop) {
                                        backdrop.style.display = 'none';
                                        backdrop.classList.remove('show');
                                    }

                                    const form = document.getElementById('createInvoiceForm');
                                    form.reset();
                                    setCurrentPeriodeToForm('createInvoiceForm');

                                    document.getElementById('createInvoiceIdpel').value = idpel;
                                    document.getElementById('createInvoicePemilik').value = pemilik;
                                    document.getElementById('createInvoiceTipeTempo').value = tipeTempo;
                                    document.getElementById('createInvoiceNamaDisplay').value = idpel + ' - ' + nama;

                                    const periodeWrap = document.getElementById('createInvoicePeriodeWrap');
                                    const tanggalWrap = document.getElementById('createInvoiceTanggalWrap');
                                    const periodeMonthInput = form.querySelector('[name="periode_month"]');
                                    const periodeYearInput = form.querySelector('[name="periode_year"]');

                                    // Rolling/Monthversary: periode & tanggal jatuh tempo dihitung otomatis di
                                    // backend (tagihanHitungJatuhTempoBerikutnya()), jadi field2 itu disembunyikan
                                    // & tidak wajib diisi -- cukup tampilkan info singkat (lihat tanggalWrap di HTML).
                                    periodeWrap.style.display = isRollingOrMv ? 'none' : 'flex';
                                    tanggalWrap.style.display = isRollingOrMv ? 'block' : 'none';
                                    periodeMonthInput.disabled = isRollingOrMv;
                                    periodeYearInput.disabled = isRollingOrMv;
                                    periodeMonthInput.required = !isRollingOrMv;
                                    periodeYearInput.required = !isRollingOrMv;

                                    document.getElementById('createInvoiceModal').style.display = 'flex';
                                    document.body.classList.add('overflow-hidden');
                                }

                                function closeCreateInvoiceModal() {
                                    document.getElementById('createInvoiceModal').style.display = 'none';
                                    document.body.classList.remove('overflow-hidden');

                                    // Restore modal Bootstrap dan backdrop
                                    if (window._createInvoiceModalBackref) {
                                        if (window._createInvoiceModalBackref.backdrop) {
                                            window._createInvoiceModalBackref.backdrop.style.display = 'block';
                                            window._createInvoiceModalBackref.backdrop.classList.add('show');
                                        }
                                        window._createInvoiceModalBackref.modals.forEach(modal => {
                                            modal.style.display = 'block';
                                            modal.classList.add('show');
                                        });
                                    }
                                }

                                document.getElementById('createInvoiceForm').addEventListener('submit', function(e) {
                                    e.preventDefault();

                                    const submitBtn = document.getElementById('createInvoiceSubmitBtn');
                                    const formData = new FormData(this);

                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Menyimpan...';

                                    fetch('proses/create_invoice_pelanggan.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        alert(data.message || 'Proses selesai.');
                                        if (data.success) {
                                            closeCreateInvoiceModal();
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Gagal membuat invoice pelanggan.');
                                    })
                                    .finally(() => {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = 'Simpan';
                                    });
                                });

                                document.getElementById('discountSettingForm').addEventListener('submit', function(e) {
                                    e.preventDefault();

                                    const submitBtn = document.getElementById('discountSubmitBtn');
                                    const formData = new FormData(this);

                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Menyimpan...';

                                    fetch('proses/save_diskon_pelanggan.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        alert(data.message || 'Proses selesai.');
                                        if (data.success) {
                                            closeDiscountSettingModal();
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Gagal menyimpan diskon pelanggan.');
                                    })
                                    .finally(() => {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = 'Simpan Diskon';
                                    });
                                });

                                document.getElementById('feeSettingForm').addEventListener('submit', function(e) {
                                    e.preventDefault();

                                    const submitBtn = document.getElementById('feeSubmitBtn');
                                    const formData = new FormData(this);

                                    submitBtn.disabled = true;
                                    submitBtn.textContent = 'Menyimpan...';

                                    fetch('proses/save_biaya_tambahan_pelanggan.php', {
                                        method: 'POST',
                                        body: formData
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        alert(data.message || 'Proses selesai.');
                                        if (data.success) {
                                            closeFeeSettingModal();
                                        }
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        alert('Gagal menyimpan tambahan biaya pelanggan.');
                                    })
                                    .finally(() => {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = 'Simpan Biaya';
                                    });
                                });

                                document.addEventListener('click', function(e) {
                                    const diskonBtn = e.target.closest('.btn-delete-active-discount');
                                    if (diskonBtn) {
                                        const id = diskonBtn.getAttribute('data-id');
                                        if (!id) return;

                                        if (!confirm('Hapus diskon aktif ini?')) {
                                            return;
                                        }

                                        const body = new URLSearchParams();
                                        body.set('id', id);

                                        fetch('proses/delete_diskon_pelanggan.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                            },
                                            body: body.toString()
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            alert(data.message || 'Proses selesai.');
                                            if (data.success) {
                                                window.location.reload();
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            alert('Gagal menghapus diskon aktif.');
                                        });
                                        return;
                                    }

                                    const feeBtn = e.target.closest('.btn-delete-active-fee');
                                    if (feeBtn) {
                                        const id = feeBtn.getAttribute('data-id');
                                        if (!id) return;

                                        if (!confirm('Hapus tambahan biaya aktif ini?')) {
                                            return;
                                        }

                                        const body = new URLSearchParams();
                                        body.set('id', id);

                                        fetch('proses/delete_biaya_tambahan_pelanggan.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                            },
                                            body: body.toString()
                                        })
                                        .then(response => response.json())
                                        .then(data => {
                                            alert(data.message || 'Proses selesai.');
                                            if (data.success) {
                                                window.location.reload();
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            alert('Gagal menghapus tambahan biaya aktif.');
                                        });
                                    }
                                });
                            </script>
                       

<?php if ($AKSES == "ADMIN") { ?>
<script>
document.querySelectorAll(".tiket.total").forEach(el => {
  const dataKey = el.getAttribute("data-id");

  function loadTiket() {
    // Jangan update badge (innerHTML) selama ada modal kecil (Manual Active, Setting
    // Diskon, dst) terbuka - update DOM di sini bisa memicu reflow yang menutup paksa
    // <select> native yang lagi dibuka user di modal tersebut. Lihat isSecondaryQtsModalOpen().
    if (typeof isSecondaryQtsModalOpen === 'function' && isSecondaryQtsModalOpen()) {
        return;
    }
    fetch("getdata/count_tiket.php?data=" + encodeURIComponent(dataKey))
      .then(res => res.json())
      .then(data => {
        let html = "";

        // Dismantel OPEN
        if (parseInt(data.dismantel) > 0) {
          html += `
            <span class="badge bg-gradient-danger fs-7 px-2 py-1">
              ${data.dismantel} tiket dismantle
            </span><br>
          `;
        }

        // Maintenance OPEN
        if (parseInt(data.maintenance) > 0) {
          html += `
            <span class="badge bg-gradient-warning fs-7 px-2 py-1">
              ${data.maintenance} tiket maintenance
            </span><br>
          `;
        }

        // Latest CANCEL team
        if (data.latest_cancel_team) {
          html += `
            <span class="badge bg-gradient-warning fs-7 px-2 py-1">
             laporan terkhir dari :<br> ${data.latest_cancel_team}
            </span>
          `;
        }

        el.innerHTML = html || "<span class='text-muted'>Tidak ada laporan</span>";
      })
      .catch(() => {
        el.innerHTML = "<span class='text-danger'>Gagal load data</span>";
      });
  }

  loadTiket();               // load awal
  setInterval(loadTiket, 10000); // refresh tiap 10 detik
});
</script>
<?php } ?>





                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- JavaScript -->

<!-- Modal: Ubah Jatuh Tempo Monthversary -->
<div class="modal fade" id="modalEditMonthversary" tabindex="-1" aria-labelledby="modalEditMonthversaryLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title text-white" id="modalEditMonthversaryLabel">Ubah Jatuh Tempo</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info small mb-3" id="editMonthversaryInfoMonthversary">
            Mode Monthversary mengunci tanggal jatuh tempo pelanggan ini ke HARI TETAP setiap bulan. Ubah tanggal
            di bawah untuk menggeser hari anchor-nya (mis. dari tanggal 10 ke tanggal 15).
        </div>
        <div class="alert alert-warning small mb-3 d-none" id="editMonthversaryInfoConvert"></div>
        <p class="mb-2">Pelanggan: <b id="editMonthversaryIdpelLabel"></b></p>
        <input type="hidden" id="editMonthversaryIdpel">
        <div class="mb-3">
            <label class="form-label">Tanggal Jatuh Tempo Baru</label>
            <input type="date" class="form-control" id="editMonthversaryTanggal" required>
        </div>
        <div id="editMonthversaryAlert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="btnSimpanEditMonthversary">
            <span class="btn-label">Simpan</span>
            <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var editMonthversaryModalInstance = null;
function openEditMonthversaryModal(idpel, currentAnchor, tipeTempo) {
    if (tipeTempo === 'mengikuti_tanggal_tempo') {
        alert('Jatuh tempo Fixed Due Date sama untuk semua pelanggan mode ini (1 pengaturan global) dan tidak bisa diedit per pelanggan.');
        return;
    }
    document.getElementById('editMonthversaryIdpel').value = idpel;
    document.getElementById('editMonthversaryIdpelLabel').textContent = idpel;
    document.getElementById('editMonthversaryTanggal').value = currentAnchor || '';
    document.getElementById('editMonthversaryAlert').innerHTML = '';

    var isMonthversary = (tipeTempo === 'monthversary');
    document.getElementById('editMonthversaryInfoMonthversary').classList.toggle('d-none', !isMonthversary);
    var convertBox = document.getElementById('editMonthversaryInfoConvert');
    convertBox.classList.toggle('d-none', isMonthversary);
    if (!isMonthversary) {
        convertBox.innerHTML = 'Tanggal jatuh tempo <b>Rolling Due Date</b> normalnya dihitung OTOMATIS dari histori ' +
            'pembayaran. Tanggal yang disimpan di sini jadi <b>override jatuh tempo berikutnya</b> (mode tempo pelanggan ' +
            'TETAP Rolling, TIDAK dikonversi) -- berlaku selama tanggalnya belum lewat; begitu pelanggan bayar sesuai/' +
            'setelah siklus itu, perhitungan otomatis dari histori pembayaran berjalan lagi seperti biasa.';
    }

    if (!editMonthversaryModalInstance) {
        editMonthversaryModalInstance = new bootstrap.Modal(document.getElementById('modalEditMonthversary'));
    }
    editMonthversaryModalInstance.show();
}

document.addEventListener('DOMContentLoaded', function() {
    var btnSimpan = document.getElementById('btnSimpanEditMonthversary');
    if (!btnSimpan) return;
    btnSimpan.addEventListener('click', function() {
        var idpel = document.getElementById('editMonthversaryIdpel').value;
        var tanggal = document.getElementById('editMonthversaryTanggal').value;
        var alertBox = document.getElementById('editMonthversaryAlert');
        if (!tanggal) {
            alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-0">Tanggal wajib diisi.</div>';
            return;
        }
        var label = btnSimpan.querySelector('.btn-label');
        var loading = btnSimpan.querySelector('.btn-loading');
        btnSimpan.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        var fd = new FormData();
        fd.append('idpel', idpel);
        fd.append('tanggal_monthversary', tanggal);

        fetch('proses/update_tanggal_monthversary.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) {
                    alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-0">' + (res.message || 'Gagal menyimpan.') + '</div>';
                    return;
                }
                alertBox.innerHTML = '<div class="alert alert-success py-2 mb-0">' + (res.message || 'Berhasil disimpan') + ', memuat ulang halaman...</div>';
                setTimeout(function() { window.location.reload(); }, 1500);
            })
            .catch(function(err) {
                alertBox.innerHTML = '<div class="alert alert-danger py-2 mb-0">Error: ' + err.message + '</div>';
            })
            .finally(function() {
                btnSimpan.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });
});
</script>

<!-- Modal -->
<div class="modal fade" id="remoteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" style="max-width:90%; height:80vh;">
    <div class="modal-content d-flex flex-column" style="height:90vh; border:none;">

      <!-- Judul di atas -->
            <div class="modal-header bg-dark text-white justify-content-between align-items-center">
                <h4 class="modal-title m-0 text-white">Remote ONT</h4>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Iframe di tengah -->
            <div class="modal-body p-0" style="position:relative;">
        <div id="remoteLoading" style="display:none; position:absolute; inset:0; z-index:2; background:#fff; flex-direction:column; align-items:center; justify-content:center;">
          <div class="spinner-border text-primary" role="status" style="width:3rem; height:3rem;"></div>
          <div class="mt-3 text-muted">Menghubungkan ke Mikrotik, mohon tunggu...</div>
        </div>
        <iframe id="remoteFrame" name="remoteFrame"
          style="width:100%; height:100%; border:none;"></iframe>
      </div>

      <!-- Tombol Tutup di bawah -->
            <div class="modal-footer bg-light justify-content-center">
        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">TUTUP</button>
      </div>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const remoteFrameEl = document.getElementById("remoteFrame");
    if (remoteFrameEl) {
        remoteFrameEl.addEventListener("load", function() {
            const loadingEl = document.getElementById("remoteLoading");
            if (loadingEl) loadingEl.style.display = "none";
        });
    }
});
</script>


                        </div>
                    </div>

       
<?php require 'footer.php'; ?>




