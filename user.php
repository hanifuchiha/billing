<?php

require 'header.php'; ?>
<style>
/* Menu Categories Styling */
.menu-category-header {
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
  padding: 12px 16px;
  border-radius: 6px;
  margin-top: 12px;
  margin-bottom: 8px;
  border-left: 4px solid #0e9589;
  position: relative;
}

.menu-category-header.technical {
  background: linear-gradient(135deg, #ffe5e5 0%, #ffcccc 100%);
  border-left-color: #dc3545;
}

.menu-category-header.general {
  background: linear-gradient(135deg, #e5f0ff 0%, #ccddff 100%);
  border-left-color: #0dcaf0;
}

.menu-category-items {
  margin-left: 16px;
  padding-left: 12px;
  border-left: 2px solid #dee2e6;
  margin-bottom: 16px;
  /* Checkbox di dalam 1 kategori dirapatkan jadi grid mini sendiri (bukan
     numpuk 1 kolom) -- lihat catatan .perm-grid di bawah soal kenapa ini
     dipisah dari grid terluar. */
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 0 16px;
}

.menu-item-checkbox {
  padding: 6px 0;
  transition: all 0.2s ease;
}

.menu-item-checkbox:hover {
  background: rgba(0,0,0,0.02);
  margin-left: -12px;
  padding-left: 12px;
  border-radius: 4px;
}

.badge-technical {
  background-color: #dc3545 !important;
  font-size: 10px;
  font-weight: 600;
}

.badge-general {
  background-color: #0dcaf0 !important;
  font-size: 10px;
  font-weight: 600;
}

/* Grid (bukan CSS multi-column) utk daftar checkbox panjang (Hak Akses Menu,
   Card Dashboard, Tombol Halaman/Group/Individual) di form Tambah/Edit
   ASSISTANT -- checkbox mengisi lebar penuh scr merata, bukan numpuk 1
   kolom dengan ruang kosong di sisi lain.
   CATATAN: sebelumnya pakai `columns:` (CSS multi-column). Itu bikin bug
   visual: kombinasi `column-fill` otomatis + `break-inside:avoid` pada
   blok kategori (header pendek + daftar item panjang) bisa bikin header
   "kepisah sendirian" di 1 kolom sementara isinya terlempar ke kolom
   berikutnya, nyisain ruang kosong di bawah header. CSS Grid tidak
   nge-balance tinggi kolom spt itu -- header/items kategori dipaksa selalu
   1 baris penuh (`grid-column:1/-1` di bawah) supaya SELALU nempel jadi 1
   unit, checkbox datar (Card Dashboard dkk, tanpa header kategori) otomatis
   mengalir rapi ke grid sesuai lebar yg tersedia. */
.perm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 4px 20px;
  align-items: start;
}
.menu-category-header,
.menu-category-items {
  grid-column: 1 / -1;
}

/* Header kategori KHUSUS untuk section "Tombol" (Masing-Masing Halaman/Group/
   Individual) -- sengaja dibuat beda tampilan dari .menu-category-header yang
   dipakai "Hak Akses Menu" di atasnya, supaya tidak ketuker: section ini
   aturan TOMBOL (sembunyikan tombol di halaman), BUKAN izin akses menu/
   halaman itu sendiri (itu urusan "Hak Akses Menu"). */
.btn-category-header {
  grid-column: 1 / -1;
  background: #eef1f4;
  padding: 6px 12px;
  border-radius: 4px;
  margin-top: 8px;
  border-left: 3px solid #6c757d;
}
.btn-category-header strong {
  font-size: 0.85rem;
  color: #495057;
}
.btn-category-items {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 2px 20px;
}
.btn-page-subheader {
  grid-column: 1 / -1;
  font-size: 0.75rem;
  font-weight: 700;
  color: #868e96;
  margin: 4px 0 0 2px;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}
</style>
       <?php
        // Logo terpusat di dokumen/logo/ (sejajar crm/). $profile_picture_file = path
        // filesystem untuk file_exists(), $profile_picture = URL web untuk <img src>.
        $profile_picture_file = __DIR__ . "/../../dokumen/logo/profile-$ceknama.png";
        $profile_picture = "/dokumen/logo/profile-$ceknama.png";

        // Pastikan file harus PNG
        if (!file_exists($profile_picture_file) || strtolower(pathinfo($profile_picture_file, PATHINFO_EXTENSION)) !== 'png') {
            $profile_picture = "/dokumen/logo/logo.png";
        }

        $dashboard_card_defaults = [
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
          'btn_group_dashboard_quick' => true,
          'btn_group_dashboard_view' => true,
          'btn_group_dashboard_export' => true,
          'btn_group_dashboard_system' => true,
          'btn_group_server_manage' => true,
          'btn_group_server_tools' => true,
          'btn_group_server_export' => true,
          'btn_group_olt_manage' => true,
          'btn_group_olt_remote' => true,
          'btn_group_olt_export' => true,
          'btn_group_customer_toolbar' => true,
          'btn_group_customer_filter' => true,
          'btn_group_customer_actions' => true,
          'btn_dash_voucher' => true,
          'btn_dash_add_customer' => true,
          'btn_dash_export_excel' => true,
          'btn_dash_export_pdf' => true,
          'btn_dash_clear_cache' => true,
          'btn_dash_refresh' => true,
          'btn_dash_ticket_edit' => true,
          'btn_dash_ticket_hapus' => true,
          'btn_server_show_interface' => true,
          'btn_server_add_data' => true,
          'btn_server_import' => true,
          'btn_server_export' => true,
          'btn_server_logo' => true,
          'btn_olt_add_data' => true,
          'btn_olt_import_data' => true,
          'btn_olt_download_template' => true,
          'btn_cust_add_customer' => true,
          'btn_cust_import_customer' => true,
          'btn_cust_scan_unregistered' => true,
          'btn_cust_hapus_massal' => true,
          'btn_cust_acs_reboot' => true,
          'btn_cust_edit_jatuh_tempo' => true,
          'btn_vpn_add_user' => true,
          'btn_vpn_delete_user' => true,
          'btn_vpn_modal_open' => true,
          'btn_odp_add' => true,
          'btn_odp_import' => true,
          'btn_odp_export_excel' => true,
          'btn_odp_delete' => true,
          'btn_tiket_filter' => true,
          'btn_tiket_update' => true,
          'btn_tiket_create' => true,
          'btn_tiket_process' => true,
          'btn_notif_save_dynamic' => true,
          'btn_notif_save_invoice' => true,
          'btn_notif_modal_save' => true,
          'buttons_livechat' => true,
          'btn_livechat_tambah_bot' => true,
          'btn_cust_buat_tiket' => true,
          'buttons_nms' => true,
          'btn_nms_tambah_device' => true,
          'btn_nms_edit_device' => true,
          'btn_nms_hapus_device' => true,
          'btn_nms_toolbar' => true,
          'buttons_ftth' => true,
          'btn_ftth_tambah' => true,
          'btn_ftth_draw_cable' => true,
          'btn_ftth_sync_odp' => true,
          'btn_ftth_export' => true,
          'btn_ftth_import' => true,
          'btn_ftth_save' => true,
          'btn_ftth_delete' => true,
          'btn_ftth_update_feature' => true,
          'buttons_broadcast' => true,
          'btn_broadcast_kirim' => true,
          'btn_broadcast_stop' => true,
          'buttons_joblist' => true,
          'btn_joblist_import' => true,
          'btn_joblist_simpan' => true,
          'btn_joblist_filter' => true,
          'btn_joblist_export' => true,
          'btn_joblist_hapus_duplikat' => true,
          'btn_joblist_assign' => true,
          'btn_joblist_kirim_wa' => true,
          'buttons_provisioning' => true,
          'btn_prov_approve' => true,
          'btn_prov_reject' => true,
          'btn_prov_reaktivasi' => true,
          'buttons_packages' => true,
          'btn_pkg_tambah' => true,
          'btn_pkg_sync' => true,
          'btn_pkg_import' => true,
          'btn_pkg_export' => true,
          'btn_pkg_edit' => true,
          'btn_pkg_hapus' => true,
          'btn_pkg_pendaftaran_setting' => true,
          'buttons_packages_hotspot' => true,
          'btn_pkgh_tambah' => true,
          'btn_pkgh_sync' => true,
          'btn_pkgh_import' => true,
          'btn_pkgh_edit' => true,
          'btn_pkgh_hapus' => true,
          'buttons_menunggak' => true,
          'btn_menunggak_cron' => true,
          'btn_menunggak_export' => true,
          'btn_menunggak_broadcast' => true,
          'btn_menunggak_buat_tiket' => true,
          'btn_menunggak_diskon' => true,
          'buttons_berhenti' => true,
          'btn_berhenti_broadcast' => true,
          'btn_berhenti_regist_ulang' => true,
          'btn_berhenti_hapus_permanen' => true,
          'buttons_voucher_gen' => true,
          'btn_voucher_buat' => true,
          'btn_voucher_template_builder' => true,
          'buttons_voucher_bank' => true,
          'btn_voucherbank_hapus' => true,
          'btn_voucherbank_cetak' => true,
          'btn_voucherbank_export' => true,
          'buttons_transaction' => true,
          'btn_trx_generate_invoice' => true,
          'btn_trx_export' => true,
          'btn_trx_print_struk' => true,
          'btn_trx_download_pdf' => true,
          'btn_trx_hapus' => true,
          'btn_trx_adjust_tanggal' => true,
          'buttons_diskon' => true,
          'btn_diskon_simpan' => true,
          'btn_diskon_nonaktifkan' => true,
          'buttons_biaya' => true,
          'btn_biaya_simpan' => true,
          'btn_biaya_nonaktifkan' => true,
          'buttons_payment_setting' => true,
          'buttons_struk_setting' => true,
          'btn_struk_simpan' => true,
          'btn_struk_logo' => true,
          'buttons_mitra' => true,
          'btn_mitra_tambah' => true,
          'btn_mitra_edit' => true,
          'btn_mitra_topup' => true,
          'btn_mitra_hapus' => true,
          'buttons_commission' => true,
          'btn_komisi_simpan_pppoe' => true,
          'btn_komisi_simpan_hotspot' => true,
          'buttons_api_integrasi' => true,
          'btn_api_regenerate_key' => true,
          'btn_api_simpan_modul' => true,
          'buttons_backup_restore' => true,
          'btn_backup_download' => true,
          'btn_backup_struktur' => true,
          'btn_restore_sekarang' => true,
          'buttons_wabot' => true,
          'btn_wabot_tambah' => true,
          'btn_wabot_login' => true,
          'btn_wabot_reconnect' => true,
          'btn_wabot_logout' => true,
          'btn_wabot_nonaktifkan' => true,
          'btn_wabot_hapus' => true,
          'btn_wabot_aktifkan' => true,
          'btn_wabot_integrasi_gateway' => true,
          'btn_wabot_advanced_settings' => true,
          'buttons_pool' => true,
          'btn_pool_sync' => true,
          'btn_pool_tambah' => true,
          'btn_pool_import' => true,
          'btn_pool_export' => true,
          'btn_pool_hapus' => true,
          'buttons_vlan' => true,
          'btn_vlan_tambah' => true,
          'btn_vlan_edit' => true,
          'btn_vlan_hapus' => true,
          'btn_vlan_sync' => true,
          'buttons_acs' => true,
          'buttons_pengeluaran' => true,
          'btn_pengeluaran_simpan' => true,
          'btn_pengeluaran_kategori' => true,
          'btn_pengeluaran_hapus' => true,
          'buttons_statistik' => true,
          'buttons_portal_setting' => true,
          'btn_portal_simpan' => true,
          'btn_portal_logo' => true,
          'buttons_komisi_pembayaran' => true,
          'btn_komisi_cron' => true,
          'btn_komisi_buat_rekap' => true,
          'btn_komisi_hapus' => true,
          'btn_komisi_acc' => true,
          'buttons_system_setting' => true,
          'btn_system_cron_dismantle' => true,
          'btn_system_cron_maintenance' => true,
          'btn_system_cron_nms' => true,
          'buttons_telegram' => true,
          'btn_telegram_tambah' => true,
          'btn_telegram_test' => true,
          'btn_telegram_hapus' => true,
          'btn_telegram_save_penerima' => true,
          'quick_actions' => true,
          'chart_pembayaran' => true,
          'transaksi_harian' => true,
          'statistik_pembayaran' => true,
          'infrastruktur_ringkasan' => true,
          'tabel_user_pppoe' => true,
          'tabel_user_hotspot' => true,
          'log_mikrotik' => true,
          'map_view' => true,
        ];

        $dashboard_card_labels = [
          'cards_semua_halaman' => 'Card Semua Halaman (Billing)',
          'buttons_semua_halaman' => 'Tombol Semua Halaman (Billing)',
          'buttons_dashboard' => '🔘 Tombol Dashboard',
          'buttons_customer' => '🔘 Tombol Customer / Pelanggan',
          'buttons_server' => '🔘 Tombol Server',
          'buttons_vpn' => '🔘 Tombol VPN',
          'buttons_tiket' => '🔘 Tombol Tiket Manager',
          'buttons_odp' => '🔘 Tombol ODP',
          'buttons_olt' => '🔘 Tombol OLT',
          'buttons_notification' => '🔘 Tombol Notifikasi',
          'btn_group_dashboard_quick' => 'Dashboard: Quick Action',
          'btn_group_dashboard_view' => 'Dashboard: Tombol Lihat Data',
          'btn_group_dashboard_export' => 'Dashboard: Tombol Export',
          'btn_group_dashboard_system' => 'Dashboard: Tombol System (clear/refresh/log)',
          'btn_group_server_manage' => 'Server: Tombol Manage (add/update)',
          'btn_group_server_tools' => 'Server: Tombol Tools/Monitoring',
          'btn_group_server_export' => 'Server: Tombol Import/Export',
          'btn_group_olt_manage' => 'OLT: Tombol Manage (add/edit/import)',
          'btn_group_olt_remote' => 'OLT: Tombol Remote/Console',
          'btn_group_olt_export' => 'OLT: Tombol Export/Template/Dokumen',
          'btn_group_customer_toolbar' => 'Customer: Toolbar utama',
          'btn_group_customer_filter' => 'Customer: Tombol Filter/Pencarian',
          'btn_group_customer_actions' => 'Customer: Tombol Aksi Pelanggan',
          'btn_dash_voucher' => 'Tombol: Dashboard Voucher Generator',
          'btn_dash_add_customer' => 'Tombol: Dashboard Add Customer',
          'btn_dash_export_excel' => 'Tombol: Dashboard Export Excel',
          'btn_dash_export_pdf' => 'Tombol: Dashboard Export PDF',
          'btn_dash_clear_cache' => 'Tombol: Dashboard Clear Cache',
          'btn_dash_refresh' => 'Tombol: Dashboard Refresh',
          'btn_dash_ticket_edit' => 'Tombol: Dashboard Edit Tiket (widget ticket)',
          'btn_dash_ticket_hapus' => 'Tombol: Dashboard Hapus Tiket (single/massal)',
          'btn_server_show_interface' => 'Tombol: Server Tampilkan Interface',
          'btn_server_add_data' => 'Tombol: Server Add/Update Data',
          'btn_server_import' => 'Tombol: Server Import Data',
          'btn_server_export' => 'Tombol: Server Export Data',
          'btn_server_logo' => 'Tombol: Server Upload/Hapus Logo',
          'btn_olt_add_data' => 'Tombol: OLT Add Data',
          'btn_olt_import_data' => 'Tombol: OLT Import Data',
          'btn_olt_download_template' => 'Tombol: OLT Download Template',
          'btn_cust_add_customer' => 'Tombol: Customer Add Customer',
          'btn_cust_import_customer' => 'Tombol: Customer Import Customer',
          'btn_cust_scan_unregistered' => 'Tombol: Customer Scan Unregistered PPPoE',
          'btn_cust_hapus_massal' => 'Tombol: Customer Hapus Terpilih (massal)',
          'btn_cust_acs_reboot' => 'Tombol: Customer Reboot Perangkat ACS/ONU',
          'btn_cust_edit_jatuh_tempo' => 'Tombol: Customer Edit Tanggal Jatuh Tempo',
          'btn_vpn_add_user' => 'Tombol: VPN Tambah User',
          'btn_vpn_delete_user' => 'Tombol: VPN Hapus User',
          'btn_vpn_modal_open' => 'Tombol: VPN Buka Modal',
          'btn_odp_add' => 'Tombol: ODP Tambah ODP',
          'btn_odp_import' => 'Tombol: ODP Import/KMZ',
          'btn_odp_export_excel' => 'Tombol: ODP Export Excel',
          'btn_odp_delete' => 'Tombol: ODP Hapus',
          'btn_tiket_filter' => 'Tombol: Tiket Filter/Reset',
          'btn_tiket_update' => 'Tombol: Tiket Update',
          'btn_tiket_create' => 'Tombol: Tiket Simpan Baru',
          'btn_tiket_process' => 'Tombol: Tiket Proses/Selesaikan (report/provisioning/dismantle)',
          'btn_notif_save_dynamic' => 'Tombol: Notification Simpan Salam Dinamis',
          'btn_notif_save_invoice' => 'Tombol: Notification Simpan Invoice Generator',
          'btn_notif_modal_save' => 'Tombol: Notification Simpan Modal',
          'buttons_livechat' => '🔘 Tombol Live Chat',
          'btn_livechat_tambah_bot' => 'Tombol: Live Chat Tambah Bot',
          'btn_cust_buat_tiket' => 'Tombol: Customer Buat Tiket',
          'buttons_nms' => '🔘 Tombol NMS',
          'btn_nms_tambah_device' => 'Tombol: NMS Tambah Device',
          'btn_nms_edit_device' => 'Tombol: NMS Edit Device',
          'btn_nms_hapus_device' => 'Tombol: NMS Hapus Device',
          'btn_nms_toolbar' => 'Tombol: NMS Pause/Clear Cache/Refresh',
          'buttons_ftth' => '🔘 Tombol Infrastructure Maps',
          'btn_ftth_tambah' => 'Tombol: Maps Tambah Marking/OLT/JC',
          'btn_ftth_draw_cable' => 'Tombol: Maps Draw Cable',
          'btn_ftth_sync_odp' => 'Tombol: Maps Sync ODP',
          'btn_ftth_export' => 'Tombol: Maps Export (GeoJSON/XLSX/KML)',
          'btn_ftth_import' => 'Tombol: Maps Import',
          'btn_ftth_save' => 'Tombol: Maps Save Feature/Attribute',
          'btn_ftth_delete' => 'Tombol: Maps Delete Feature',
          'btn_ftth_update_feature' => 'Tombol: Maps Update Feature',
          'buttons_broadcast' => '🔘 Tombol Broadcast Info',
          'btn_broadcast_kirim' => 'Tombol: Broadcast Kirim',
          'btn_broadcast_stop' => 'Tombol: Broadcast Stop',
          'buttons_joblist' => '🔘 Tombol Ticket Monitoring',
          'btn_joblist_import' => 'Tombol: Joblist Import Excel',
          'btn_joblist_simpan' => 'Tombol: Joblist Simpan Tiket Manual',
          'btn_joblist_filter' => 'Tombol: Joblist Filter/Search',
          'btn_joblist_export' => 'Tombol: Joblist Export',
          'btn_joblist_hapus_duplikat' => 'Tombol: Joblist Hapus Duplikat',
          'btn_joblist_assign' => 'Tombol: Joblist Assign Tickets',
          'btn_joblist_kirim_wa' => 'Tombol: Joblist Kirim WA Report',
          'buttons_provisioning' => '🔘 Tombol Provisioning Joblist',
          'btn_prov_approve' => 'Tombol: Provisioning Approve',
          'btn_prov_reject' => 'Tombol: Provisioning Reject',
          'btn_prov_reaktivasi' => 'Tombol: Provisioning Reaktivasi',
          'buttons_packages' => '🔘 Tombol Packages Broadband',
          'btn_pkg_tambah' => 'Tombol: Packages Tambah Paket',
          'btn_pkg_sync' => 'Tombol: Packages Sync MikroTik',
          'btn_pkg_import' => 'Tombol: Packages Import',
          'btn_pkg_export' => 'Tombol: Packages Export/Template',
          'btn_pkg_edit' => 'Tombol: Packages Edit',
          'btn_pkg_hapus' => 'Tombol: Packages Hapus',
          'btn_pkg_pendaftaran_setting' => 'Tombol: Packages Pengaturan Pendaftaran',
          'buttons_packages_hotspot' => '🔘 Tombol Packages Hotspot',
          'btn_pkgh_tambah' => 'Tombol: Packages Hotspot Tambah Paket',
          'btn_pkgh_sync' => 'Tombol: Packages Hotspot Sync MikroTik',
          'btn_pkgh_import' => 'Tombol: Packages Hotspot Import',
          'btn_pkgh_edit' => 'Tombol: Packages Hotspot Edit',
          'btn_pkgh_hapus' => 'Tombol: Packages Hotspot Hapus',
          'buttons_menunggak' => '🔘 Tombol Pelanggan Menunggak',
          'btn_menunggak_cron' => 'Tombol: Menunggak Cron (Log/Run/Toggle/Interval)',
          'btn_menunggak_export' => 'Tombol: Menunggak Export Excel/PDF',
          'btn_menunggak_broadcast' => 'Tombol: Menunggak Broadcast',
          'btn_menunggak_buat_tiket' => 'Tombol: Menunggak Buat Tiket Massal',
          'btn_menunggak_diskon' => 'Tombol: Menunggak Simpan Diskon Massal',
          'buttons_berhenti' => '🔘 Tombol Pelanggan Berhenti',
          'btn_berhenti_broadcast' => 'Tombol: Berhenti Broadcast',
          'btn_berhenti_regist_ulang' => 'Tombol: Berhenti Regist Ulang',
          'btn_berhenti_hapus_permanen' => 'Tombol: Berhenti Hapus Permanen',
          'buttons_voucher_gen' => '🔘 Tombol Voucher Generator',
          'btn_voucher_buat' => 'Tombol: Voucher Generator Buat Voucher',
          'btn_voucher_template_builder' => 'Tombol: Voucher Generator Buat Template Sendiri',
          'buttons_voucher_bank' => '🔘 Tombol Voucher Bank',
          'btn_voucherbank_hapus' => 'Tombol: Voucher Bank Hapus',
          'btn_voucherbank_cetak' => 'Tombol: Voucher Bank Cetak',
          'btn_voucherbank_export' => 'Tombol: Voucher Bank Export Excel',
          'buttons_transaction' => '🔘 Tombol Transaction',
          'btn_trx_generate_invoice' => 'Tombol: Transaction Generate Invoice Manual',
          'btn_trx_export' => 'Tombol: Transaction Export PDF/Excel',
          'btn_trx_print_struk' => 'Tombol: Transaction Print Struk',
          'btn_trx_download_pdf' => 'Tombol: Transaction Download PDF Struk',
          'btn_trx_hapus' => 'Tombol: Transaction Hapus',
          'btn_trx_adjust_tanggal' => 'Tombol: Transaction Penyesuaian Tanggal Bayar & Jatuh Tempo (Excel)',
          'buttons_diskon' => '🔘 Tombol Diskon',
          'btn_diskon_simpan' => 'Tombol: Diskon Simpan',
          'btn_diskon_nonaktifkan' => 'Tombol: Diskon Nonaktifkan',
          'buttons_biaya' => '🔘 Tombol Tambahan Biaya',
          'btn_biaya_simpan' => 'Tombol: Tambahan Biaya Simpan',
          'btn_biaya_nonaktifkan' => 'Tombol: Tambahan Biaya Nonaktifkan',
          'buttons_payment_setting' => '🔘 Tombol Payment Setting (semua gateway)',
          'buttons_struk_setting' => '🔘 Tombol Pengaturan Struk',
          'btn_struk_simpan' => 'Tombol: Struk Simpan Pengaturan',
          'btn_struk_logo' => 'Tombol: Struk Upload/Hapus Logo',
          'buttons_mitra' => '🔘 Tombol Mitra Accounts',
          'btn_mitra_tambah' => 'Tombol: Mitra Tambah',
          'btn_mitra_edit' => 'Tombol: Mitra Edit',
          'btn_mitra_topup' => 'Tombol: Mitra Topup',
          'btn_mitra_hapus' => 'Tombol: Mitra Hapus',
          'buttons_commission' => '🔘 Tombol Commission Setting',
          'btn_komisi_simpan_pppoe' => 'Tombol: Commission Simpan PPPoE',
          'btn_komisi_simpan_hotspot' => 'Tombol: Commission Simpan Hotspot',
          'buttons_api_integrasi' => '🔘 Tombol API Integration',
          'btn_api_regenerate_key' => 'Tombol: API Regenerate Key',
          'btn_api_simpan_modul' => 'Tombol: API Simpan Module Toggles',
          'buttons_backup_restore' => '🔘 Tombol Backup & Restore',
          'btn_backup_download' => 'Tombol: Backup Download Backup',
          'btn_backup_struktur' => 'Tombol: Backup Download Struktur DB',
          'btn_restore_sekarang' => 'Tombol: Backup Restore Sekarang',
          'buttons_wabot' => '🔘 Tombol Whatsapp BOT',
          'btn_wabot_tambah' => 'Tombol: Wabot Tambah Bot/Integrasi',
          'btn_wabot_login' => 'Tombol: Wabot Login Bot',
          'btn_wabot_reconnect' => 'Tombol: Wabot Reconnect Bot',
          'btn_wabot_logout' => 'Tombol: Wabot Logout Bot',
          'btn_wabot_nonaktifkan' => 'Tombol: Wabot Nonaktifkan Integrasi',
          'btn_wabot_hapus' => 'Tombol: Wabot Hapus Integrasi',
          'btn_wabot_aktifkan' => 'Tombol: Wabot Aktifkan Integrasi',
          'btn_wabot_integrasi_gateway' => 'Tombol: Wabot Tambah/Test Integrasi Gateway (resmi/tidak resmi)',
          'btn_wabot_advanced_settings' => 'Tombol: Wabot Pengaturan Lanjutan (Database/Function/Port)',
          'buttons_pool' => '🔘 Tombol IP Pool',
          'btn_pool_sync' => 'Tombol: IP Pool Sync dari Server',
          'btn_pool_tambah' => 'Tombol: IP Pool Buat Pool Baru',
          'btn_pool_import' => 'Tombol: IP Pool Import Excel',
          'btn_pool_export' => 'Tombol: IP Pool Export/Template',
          'btn_pool_hapus' => 'Tombol: IP Pool Hapus Terpilih',
          'buttons_vlan' => '🔘 Tombol VLAN',
          'btn_vlan_tambah' => 'Tombol: VLAN Tambah VLAN',
          'btn_vlan_edit' => 'Tombol: VLAN Edit',
          'btn_vlan_hapus' => 'Tombol: VLAN Hapus',
          'btn_vlan_sync' => 'Tombol: VLAN Sync dari Router',
          'buttons_acs' => '🔘 Tombol Informasi Server ACS',
          'buttons_pengeluaran' => '🔘 Tombol Laporan Pengeluaran',
          'btn_pengeluaran_simpan' => 'Tombol: Pengeluaran Tambah/Update',
          'btn_pengeluaran_kategori' => 'Tombol: Pengeluaran Tambah Kategori',
          'btn_pengeluaran_hapus' => 'Tombol: Pengeluaran Hapus',
          'buttons_statistik' => '🔘 Tombol Statistik dan Laporan',
          'buttons_portal_setting' => '🔘 Tombol Pengaturan Halaman Pelanggan (Nama Product/Logo/FAQ/Refund/S&K/Kontak)',
          'btn_portal_simpan' => 'Tombol: Pengaturan Halaman Pelanggan Simpan',
          'btn_portal_logo' => 'Tombol: Pengaturan Halaman Pelanggan Upload/Hapus Logo',
          'buttons_komisi_pembayaran' => '🔘 Tombol Pembayaran Komisi',
          'btn_komisi_cron' => 'Tombol: Komisi Simpan Jadwal Cron',
          'btn_komisi_buat_rekap' => 'Tombol: Komisi Buat Rekap',
          'btn_komisi_hapus' => 'Tombol: Komisi Hapus Transaksi',
          'btn_komisi_acc' => 'Tombol: Komisi ACC Transaksi',
          'buttons_system_setting' => '🔘 Tombol System Setting',
          'btn_system_cron_dismantle' => 'Tombol: System Setting Cron Dismantle Billing',
          'btn_system_cron_maintenance' => 'Tombol: System Setting Cron Maintenance No-Payment',
          'btn_system_cron_nms' => 'Tombol: System Setting Cron NMS Poll (Historis/Alert)',
          'buttons_telegram' => '🔘 Tombol Telegram Bot',
          'btn_telegram_tambah' => 'Tombol: Telegram Bot Tambah Bot',
          'btn_telegram_test' => 'Tombol: Telegram Bot Test',
          'btn_telegram_hapus' => 'Tombol: Telegram Bot Hapus',
          'btn_telegram_save_penerima' => 'Tombol: Telegram Bot Simpan Penerima Notif',
          'quick_actions' => 'Quick Actions',
          'chart_pembayaran' => 'Grafik Pembayaran',
          'transaksi_harian' => 'Transaksi Harian',
          'statistik_pembayaran' => 'Statistik & Laporan Pembayaran',
          'infrastruktur_ringkasan' => 'Ringkasan Infrastruktur',
          'tabel_user_pppoe' => 'Card Total Paket PPPoE',
          'tabel_user_hotspot' => 'Card Total Paket Hotspot',
          'log_mikrotik' => 'System Log & Log MikroTik',
          'map_view' => 'Customer & ODP Maps',
        ];

        /**
         * Petakan key tombol (btn_group_* / btn_*) ke label halaman asalnya --
         * dipakai utk mengelompokkan daftar "Group Tombol per Halaman" & "Tombol
         * Individual" yang tadinya 1 daftar panjang tak terkelompok, supaya mudah
         * dicari (sama pola visual dgn kategori "Hak Akses Menu" di atas).
         * Prefix diturunkan dari konvensi penamaan key yg sudah ada (btn_dash_ =
         * Dashboard, btn_cust_ = Customer PPPOE, dst) -- BUKAN dari urutan array
         * (urutan $dashboard_card_labels ternyata tidak konsisten per-halaman).
         */
        function get_button_page_group($key) {
          // Kasus ambigu: prefix btn_komisi_ dipakai 2 halaman beda (Commission
          // Setting vs Pembayaran Komisi), dibedakan dari key persisnya dulu
          // sebelum jatuh ke pencocokan prefix generik di bawah.
          if ($key === 'btn_komisi_simpan_pppoe' || $key === 'btn_komisi_simpan_hotspot') {
            return 'Commission Setting';
          }
          if (strpos($key, 'btn_komisi_') === 0) {
            return 'Pembayaran Komisi';
          }

          $prefixMap = [
            'btn_group_dashboard_' => 'Dashboard',
            'btn_group_server_' => 'Server Area',
            'btn_group_olt_' => 'OLT',
            'btn_group_customer_' => 'Customer PPPOE',
            'btn_dash_' => 'Dashboard',
            'btn_server_' => 'Server Area',
            'btn_olt_' => 'OLT',
            'btn_cust_' => 'Customer PPPOE',
            'btn_vpn_' => 'Koneksi VPN',
            'btn_odp_' => 'Mapping ODP',
            'btn_tiket_' => 'Ticket Manager',
            'btn_notif_' => 'Notification Settings',
            'btn_livechat_' => 'Live Chat',
            'btn_nms_' => 'NMS',
            'btn_ftth_' => 'Infrastructure Maps',
            'btn_broadcast_' => 'Broadcast Info',
            'btn_joblist_' => 'Ticket Monitoring',
            'btn_prov_' => 'Provisioning Joblist',
            'btn_pkgh_' => 'Packages Hotspot',
            'btn_pkg_' => 'Packages Broadband',
            'btn_menunggak_' => 'Pelanggan Menunggak',
            'btn_berhenti_' => 'Pelanggan Berhenti',
            'btn_voucherbank_' => 'Voucher Bank',
            'btn_voucher_' => 'Voucher Generator',
            'btn_trx_' => 'Transaction',
            'btn_diskon_' => 'Diskon',
            'btn_biaya_' => 'Tambahan Biaya',
            'btn_struk_' => 'Pengaturan Struk',
            'btn_mitra_' => 'Mitra Accounts',
            'btn_api_' => 'API Integration',
            'btn_backup_' => 'Backup & Restore',
            'btn_restore_' => 'Backup & Restore',
            'btn_wabot_' => 'Whatsapp BOT',
            'btn_pool_' => 'IP Pool',
            'btn_vlan_' => 'VLAN',
            'btn_pengeluaran_' => 'Laporan Pengeluaran',
            'btn_portal_' => 'Pengaturan Halaman Pelanggan',
            'btn_system_' => 'System Setting',
            'btn_telegram_' => 'Telegram Bot',
          ];

          foreach ($prefixMap as $prefix => $label) {
            if (strpos($key, $prefix) === 0) {
              return $label;
            }
          }
          return 'Lainnya';
        }

        /**
         * Kategori BESAR (sama persis dgn kategori "Hak Akses Menu" di atas)
         * untuk key buttons_X / btn_group_X / btn_X -- dipakai sbg grouping
         * level-luar di 3 section "Tombol" (Masing-Masing Halaman/Group/
         * Individual) supaya gampang dicari & konsisten satu sama lain.
         */
        function get_button_broad_category($key) {
          if ($key === 'btn_komisi_simpan_pppoe' || $key === 'btn_komisi_simpan_hotspot') {
            return 'management'; // Commission Setting
          }
          $prefixCategoryMap = [
            'buttons_dashboard' => 'support', 'buttons_notification' => 'support', 'buttons_livechat' => 'support',
            'btn_group_dashboard_' => 'support', 'btn_dash_' => 'support', 'btn_notif_' => 'support', 'btn_livechat_' => 'support',
            'buttons_server' => 'technical', 'buttons_vpn' => 'technical', 'buttons_odp' => 'technical',
            'buttons_olt' => 'technical', 'buttons_nms' => 'technical', 'buttons_ftth' => 'technical',
            'buttons_broadcast' => 'technical', 'buttons_pool' => 'technical', 'buttons_vlan' => 'technical', 'buttons_acs' => 'technical',
            'btn_group_server_' => 'technical', 'btn_server_' => 'technical', 'btn_group_olt_' => 'technical', 'btn_olt_' => 'technical',
            'btn_vpn_' => 'technical', 'btn_odp_' => 'technical', 'btn_nms_' => 'technical', 'btn_ftth_' => 'technical',
            'btn_broadcast_' => 'technical', 'btn_pool_' => 'technical', 'btn_vlan_' => 'technical',
            'buttons_customer' => 'customer', 'buttons_packages' => 'customer', 'buttons_packages_hotspot' => 'customer',
            'buttons_menunggak' => 'customer', 'buttons_berhenti' => 'customer',
            'btn_group_customer_' => 'customer', 'btn_cust_' => 'customer', 'btn_pkgh_' => 'customer', 'btn_pkg_' => 'customer',
            'btn_menunggak_' => 'customer', 'btn_berhenti_' => 'customer',
            'buttons_voucher_gen' => 'financial', 'buttons_voucher_bank' => 'financial', 'buttons_transaction' => 'financial',
            'buttons_diskon' => 'financial', 'buttons_biaya' => 'financial', 'buttons_payment_setting' => 'financial',
            'buttons_struk_setting' => 'financial', 'buttons_pengeluaran' => 'financial', 'buttons_statistik' => 'financial',
            'buttons_portal_setting' => 'financial',
            'btn_voucherbank_' => 'financial', 'btn_voucher_' => 'financial', 'btn_trx_' => 'financial', 'btn_diskon_' => 'financial',
            'btn_biaya_' => 'financial', 'btn_struk_' => 'financial', 'btn_pengeluaran_' => 'financial', 'btn_portal_' => 'financial',
            'buttons_provisioning' => 'management', 'buttons_mitra' => 'management', 'buttons_commission' => 'management',
            'buttons_komisi_pembayaran' => 'management', 'buttons_api_integrasi' => 'management',
            'buttons_backup_restore' => 'management', 'buttons_system_setting' => 'management',
            'btn_prov_' => 'management', 'btn_mitra_' => 'management', 'btn_komisi_' => 'management', 'btn_api_' => 'management',
            'btn_backup_' => 'management', 'btn_restore_' => 'management', 'btn_system_' => 'management',
            'buttons_tiket' => 'ticketing', 'buttons_joblist' => 'ticketing',
            'btn_tiket_' => 'ticketing', 'btn_joblist_' => 'ticketing',
            'buttons_wabot' => 'billing', 'buttons_telegram' => 'billing',
            'btn_wabot_' => 'billing', 'btn_telegram_' => 'billing',
          ];
          foreach ($prefixCategoryMap as $prefix => $cat) {
            if (strpos($key, $prefix) === 0) {
              return $cat;
            }
          }
          return 'lainnya';
        }

        $button_category_labels = [
          'support' => '👥 SUPPORT & GENERAL',
          'technical' => '⚙️ TEKNIS / JARINGAN (Technical)',
          'customer' => '👨‍💼 PELANGGAN / CUSTOMER (Technical)',
          'financial' => '💰 KEUANGAN / FINANCIAL (Technical)',
          'management' => '📊 MANAJEMEN / MANAGEMENT (Technical)',
          'ticketing' => '🎫 TICKETING (Technical)',
          'billing' => '🌐 BILLING (Technical)',
          'lainnya' => '❓ LAINNYA',
        ];

        /**
         * Kelompokkan array [key => label] jadi [kategori => ['label'=>...,
         * 'pages' => [halaman => [key => label]]]] -- dua level: kategori
         * besar (luar) lalu halaman (dalam), dipakai di 3 section "Tombol".
         */
        function group_buttons_by_category_and_page(array $keys, array $categoryLabels) {
          $grouped = [];
          foreach ($keys as $key => $label) {
            $cat = get_button_broad_category($key);
            $page = get_button_page_group($key);
            $grouped[$cat]['label'] = $categoryLabels[$cat] ?? $cat;
            $grouped[$cat]['pages'][$page][$key] = $label;
          }
          // Urutkan sesuai urutan $categoryLabels (support, technical, ... , lainnya)
          $ordered = [];
          foreach ($categoryLabels as $catKey => $catLabel) {
            if (isset($grouped[$catKey])) {
              ksort($grouped[$catKey]['pages'], SORT_STRING | SORT_FLAG_CASE);
              $ordered[$catKey] = $grouped[$catKey];
            }
          }
          return $ordered;
        }

        function get_dashboard_card_settings($username, $defaults) {
          if (!is_array($defaults)) {
            return [];
          }

          $safe_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
          if ($safe_username === '') {
            return $defaults;
          }

          $dir = __DIR__ . '/settings';
          $file = $dir . '/dashboard-cards-' . $safe_username . '.json';

          if (!is_file($file)) {
            return $defaults;
          }

          $raw = @file_get_contents($file);
          $decoded = json_decode((string)$raw, true);
          if (!is_array($decoded)) {
            return $defaults;
          }

          $result = $defaults;
          foreach ($defaults as $key => $default_val) {
            if (array_key_exists($key, $decoded)) {
              $result[$key] = (bool)$decoded[$key];
            }
          }
          return $result;
        }

        function save_dashboard_card_settings($username, $settings, $defaults) {
          $safe_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
          if ($safe_username === '') {
            return false;
          }

          $dir = __DIR__ . '/settings';
          if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
          }

          if (!is_dir($dir) || !is_writable($dir)) {
            return false;
          }

          $normalized = $defaults;
          if (is_array($settings)) {
            foreach ($defaults as $key => $default_val) {
              if (array_key_exists($key, $settings)) {
                $normalized[$key] = (bool)$settings[$key];
              }
            }
          }

          $file = $dir . '/dashboard-cards-' . $safe_username . '.json';
          return @file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
        }

        $dashboard_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $username;
        $dashboard_card_settings = get_dashboard_card_settings($dashboard_settings_username, $dashboard_card_defaults);

        $profile_ticket_source = isset($ticket_management_source) ? (string)$ticket_management_source : 'tiket_manager';
        if (!in_array($profile_ticket_source, ['tiket_manager', 'joblist'], true)) {
          $profile_ticket_source = 'tiket_manager';
        }
        if ($AKSES === 'USER') {
          $profile_ticket_source = 'tiket_manager';
        }




// Get user to edit
$user_to_edit = null;
$assistant_dashboard_card_settings = $dashboard_card_defaults;
if(isset($_GET['edit'])){
    $user_id = $_GET['edit'];
    $result = $conn->query("SELECT * FROM user WHERE id = $user_id");
    if($result && $result->num_rows > 0){
        $user_to_edit = $result->fetch_assoc();
    $assistant_dashboard_card_settings = get_dashboard_card_settings($user_to_edit['USERNAME'] ?? '', $dashboard_card_defaults);

        if($user_to_edit['server']) {
            $user_servers = json_decode($user_to_edit['server'], true);
            if(!is_array($user_servers)) {
                $user_servers = [$user_to_edit['server']];
            }
        } else {
            $user_servers = [];
        }

        if($user_to_edit['akses']) {
            $user_akses = json_decode($user_to_edit['akses'], true);
            if(!is_array($user_akses)) {
                $user_akses = [$user_to_edit['akses']];
            }
        } else {
            $user_akses = [];
        }

        if(!empty($user_to_edit['assigned_bots'])) {
            $user_assigned_bots = json_decode($user_to_edit['assigned_bots'], true);
            if(!is_array($user_assigned_bots)) {
                $user_assigned_bots = [];
            }
        } else {
            $user_assigned_bots = [];
        }

        if(!empty($user_to_edit['assigned_telegram_bots'])) {
            $user_assigned_telegram_bots = json_decode($user_to_edit['assigned_telegram_bots'], true);
            if(!is_array($user_assigned_telegram_bots)) {
                $user_assigned_telegram_bots = [];
            }
        } else {
            $user_assigned_telegram_bots = [];
        }
    }
}

// Add user
if(isset($_POST['add_user'])){
  $username2 = mysqli_real_escape_string($conn, $_POST['username2']);
  $password2 = password_hash($_POST['password2'], PASSWORD_DEFAULT);
  $status2 = mysqli_real_escape_string($conn, $_POST['status2']);
  $nowa2 = mysqli_real_escape_string($conn, $_POST['nowa2']);
  $email2 = mysqli_real_escape_string($conn, $_POST['email2']);
  $saldo = 0;
  $created_at = mysqli_real_escape_string($conn, date('Y-m-d H:i:s'));

  $assistant_role = isset($_POST['assistant_role']) ? trim((string)$_POST['assistant_role']) : 'assistant';
  if (!in_array($assistant_role, ['assistant_teknisi', 'reseller', 'mitra_isp'], true)) {
    $assistant_role = 'assistant';
  }
  $assistant_role_esc = mysqli_real_escape_string($conn, $assistant_role);

  $reseller_price_filter_enabled2 = isset($_POST['reseller_price_filter_enabled']) ? 1 : 0;
  $reseller_bw_cost2 = isset($_POST['reseller_bw_cost']) ? (float)$_POST['reseller_bw_cost'] : 0;
  $reseller_bw_ppn_percent2 = isset($_POST['reseller_bw_ppn_percent']) ? (float)$_POST['reseller_bw_ppn_percent'] : 11;
  $reseller_bw_bhp_uso2 = isset($_POST['reseller_bw_bhp_uso']) ? (float)$_POST['reseller_bw_bhp_uso'] : 0;

  $akses_menu = isset($_POST['menu']) && is_array($_POST['menu']) ? $_POST['menu'] : [];
  $akses_menu = array_values(array_unique(array_filter($akses_menu)));

  // Rule role ASSISTANT
  if ($assistant_role === 'assistant_teknisi') {
    // ASSISTANT TEKNISI wajib punya Ticket Manager (menu lain tetap boleh dicentang)
    if (!in_array('Ticket_manager', $akses_menu, true)) {
      $akses_menu[] = 'Ticket_manager';
    }
    // Tidak wajib Dasbor untuk assistant teknisi
    $akses_menu = array_values(array_filter($akses_menu, function($m) {
      return $m !== 'Dasbor';
    }));
  } else {
    // ASSISTANT biasa dan RESELLER wajib punya Dasbor
    if (!in_array('Dasbor', $akses_menu, true)) {
      $akses_menu[] = 'Dasbor';
    }
  }

  $akses_json = mysqli_real_escape_string($conn, json_encode($akses_menu));

  $assistant_dashboard_cards = isset($_POST['assistant_dashboard_cards']) && is_array($_POST['assistant_dashboard_cards']) ? $_POST['assistant_dashboard_cards'] : [];
  $assistant_dashboard_cards = array_values(array_unique(array_filter(array_map('strval', $assistant_dashboard_cards))));
  $assistant_dashboard_settings = [];
  foreach ($dashboard_card_defaults as $card_key => $default_val) {
    $assistant_dashboard_settings[$card_key] = in_array($card_key, $assistant_dashboard_cards, true);
  }

  $servers = isset($_POST['server']) ? $_POST['server'] : '';
  $servers_json = mysqli_real_escape_string($conn, json_encode($servers));

  $assigned_bots = isset($_POST['assigned_bots']) ? $_POST['assigned_bots'] : [];
  $assigned_bots_json = mysqli_real_escape_string($conn, json_encode($assigned_bots));

  $assigned_telegram_bots = isset($_POST['assigned_telegram_bots']) ? $_POST['assigned_telegram_bots'] : [];
  $assigned_telegram_bots_json = mysqli_real_escape_string($conn, json_encode($assigned_telegram_bots));



  // Pastikan $inisial dan $expired_at ada
  $inisial = isset($_POST['inisial']) ? mysqli_real_escape_string($conn, $_POST['inisial']) : 'aa';

  $current_user_id = mysqli_real_escape_string($conn, $current_user_id);

  $sql2 = "INSERT INTO user (USERNAME, PASWORD, STATUS, grup, NOWA, saldo, server, assigned_bots, assigned_telegram_bots, domain, inisial, akses, created_at, expired_at, assistant_role, reseller_price_filter_enabled, reseller_bw_cost, reseller_bw_ppn_percent, reseller_bw_bhp_uso)
      VALUES ('$username2', '$password2', '$status2', '$current_user_id', '$nowa2', $saldo, '$servers_json', '$assigned_bots_json', '$assigned_telegram_bots_json', '$email2', '$inisial', '$akses_json', '$created_at', '$expired_at', '$assistant_role_esc', $reseller_price_filter_enabled2, $reseller_bw_cost2, $reseller_bw_ppn_percent2, $reseller_bw_bhp_uso2)";

  if ($conn->query($sql2) === TRUE) {
    save_dashboard_card_settings($username2, $assistant_dashboard_settings, $dashboard_card_defaults);
    echo "<script>alert('User berhasil ditambahkan');window.location='';</script>";
  } else {
    echo "<div style='color:red;'><b>SQL ERROR:</b> ".$conn->error."<br><b>QUERY:</b> ".$sql2."</div>";
  }
}

// Edit user
if(isset($_POST['edit_user'])){
    $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
    $username2 = mysqli_real_escape_string($conn, $_POST['username2']);
    $password2 = $_POST['password2'];
    $status2 = mysqli_real_escape_string($conn, $_POST['status2']);
    $nowa2 = mysqli_real_escape_string($conn, $_POST['nowa2']);
    $email2 = mysqli_real_escape_string($conn, $_POST['email2']);

    $assistant_role = isset($_POST['assistant_role']) ? trim((string)$_POST['assistant_role']) : 'assistant';
    if (!in_array($assistant_role, ['assistant_teknisi', 'reseller', 'mitra_isp'], true)) {
      $assistant_role = 'assistant';
    }
    $assistant_role_esc = mysqli_real_escape_string($conn, $assistant_role);

    $reseller_price_filter_enabled2 = isset($_POST['reseller_price_filter_enabled']) ? 1 : 0;
    $reseller_bw_cost2 = isset($_POST['reseller_bw_cost']) ? (float)$_POST['reseller_bw_cost'] : 0;
    $reseller_bw_ppn_percent2 = isset($_POST['reseller_bw_ppn_percent']) ? (float)$_POST['reseller_bw_ppn_percent'] : 11;
    $reseller_bw_bhp_uso2 = isset($_POST['reseller_bw_bhp_uso']) ? (float)$_POST['reseller_bw_bhp_uso'] : 0;

    $akses_menu = isset($_POST['menu']) && is_array($_POST['menu']) ? $_POST['menu'] : [];
    $akses_menu = array_values(array_unique(array_filter($akses_menu)));

    // Rule role ASSISTANT
    if ($assistant_role === 'assistant_teknisi') {
      // ASSISTANT TEKNISI wajib punya Ticket Manager (menu lain tetap boleh dicentang)
      if (!in_array('Ticket_manager', $akses_menu, true)) {
        $akses_menu[] = 'Ticket_manager';
      }
      // Tidak wajib Dasbor untuk assistant teknisi
      $akses_menu = array_values(array_filter($akses_menu, function($m) {
        return $m !== 'Dasbor';
      }));
    } else {
      // ASSISTANT biasa dan RESELLER wajib punya Dasbor
      if (!in_array('Dasbor', $akses_menu, true)) {
        $akses_menu[] = 'Dasbor';
      }
    }

    $akses_json = mysqli_real_escape_string($conn, json_encode($akses_menu));

    $assistant_dashboard_cards = isset($_POST['assistant_dashboard_cards']) && is_array($_POST['assistant_dashboard_cards']) ? $_POST['assistant_dashboard_cards'] : [];
    $assistant_dashboard_cards = array_values(array_unique(array_filter(array_map('strval', $assistant_dashboard_cards))));
    $assistant_dashboard_settings = [];
    foreach ($dashboard_card_defaults as $card_key => $default_val) {
      $assistant_dashboard_settings[$card_key] = in_array($card_key, $assistant_dashboard_cards, true);
    }

    $servers = isset($_POST['server']) ? $_POST['server'] : '';
    $servers_json = mysqli_real_escape_string($conn, json_encode($servers));

    $assigned_bots = isset($_POST['assigned_bots']) ? $_POST['assigned_bots'] : [];
    $assigned_bots_json = mysqli_real_escape_string($conn, json_encode($assigned_bots));

    $assigned_telegram_bots = isset($_POST['assigned_telegram_bots']) ? $_POST['assigned_telegram_bots'] : [];
    $assigned_telegram_bots_json = mysqli_real_escape_string($conn, json_encode($assigned_telegram_bots));

    if(!empty($password2)) {
      $hashed_password = password_hash($password2, PASSWORD_DEFAULT);
      $sql2 = "UPDATE user SET
          USERNAME = '$username2',
          PASWORD = '$hashed_password',
          STATUS = '$status2',
          grup = '$current_user_id',
          NOWA = '$nowa2',
          server = '$servers_json',
          assigned_bots = '$assigned_bots_json',
          assigned_telegram_bots = '$assigned_telegram_bots_json',

          akses = '$akses_json',
          assistant_role = '$assistant_role_esc',
          reseller_price_filter_enabled = $reseller_price_filter_enabled2,
          reseller_bw_cost = $reseller_bw_cost2,
          reseller_bw_ppn_percent = $reseller_bw_ppn_percent2,
          reseller_bw_bhp_uso = $reseller_bw_bhp_uso2
          WHERE id = $user_id";
    } else {
      $sql2 = "UPDATE user SET
          USERNAME = '$username2',
          STATUS = '$status2',
          grup = '$current_user_id',
          NOWA = '$nowa2',
          server = '$servers_json',
          assigned_bots = '$assigned_bots_json',
          assigned_telegram_bots = '$assigned_telegram_bots_json',

          akses = '$akses_json',
          assistant_role = '$assistant_role_esc',
          reseller_price_filter_enabled = $reseller_price_filter_enabled2,
          reseller_bw_cost = $reseller_bw_cost2,
          reseller_bw_ppn_percent = $reseller_bw_ppn_percent2,
          reseller_bw_bhp_uso = $reseller_bw_bhp_uso2
          WHERE id = $user_id";
    }

    if ($conn->query($sql2) === TRUE) {
    save_dashboard_card_settings($username2, $assistant_dashboard_settings, $dashboard_card_defaults);
    echo "<script>alert('User berhasil ditambahkan');window.location='';</script>";
    } else {
      echo "<script>alert('Error: " . $conn->error . "');</script>";
    }
}

// Update profile (OWNER dan ASSISTANT)
if(isset($_POST['save'])){
  $username2 = mysqli_real_escape_string($conn, $_POST['username']);
  $domain2 = mysqli_real_escape_string($conn, $_POST['domain']);
  $nowa2 = mysqli_real_escape_string($conn, $_POST['whatsapp']);
  $password = isset($_POST['password']) ? $_POST['password'] : '';
  $posted_dashboard_cards = isset($_POST['dashboard_cards']) && is_array($_POST['dashboard_cards']) ? $_POST['dashboard_cards'] : [];
  $posted_dashboard_cards = array_values(array_unique(array_filter(array_map('strval', $posted_dashboard_cards))));
  $posted_ticket_source = strtolower(trim((string)($_POST['ticket_management_source'] ?? 'tiket_manager')));
  $effective_ticket_source = in_array($posted_ticket_source, ['tiket_manager', 'joblist'], true) ? $posted_ticket_source : 'tiket_manager';
  if ($AKSES === 'USER') {
    $effective_ticket_source = 'tiket_manager';
  }
  if ($AKSES === 'ASSISTANT') {
    $effective_ticket_source = isset($ticket_management_source) && in_array($ticket_management_source, ['tiket_manager', 'joblist'], true)
      ? $ticket_management_source
      : 'tiket_manager';
  }

  $new_dashboard_settings = [];
  foreach ($dashboard_card_defaults as $card_key => $default_val) {
    $new_dashboard_settings[$card_key] = in_array($card_key, $posted_dashboard_cards, true);
  }
  $id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

  if ($id <= 0) {
    echo "<script>alert('Session user tidak valid. Silakan login ulang.'); window.location='logout.php';</script>";
    exit;
  }

  $set_inisial = '';
  if ($AKSES != 'ASSISTANT' && isset($_POST['inisial'])) {
    $inisial2 = mysqli_real_escape_string($conn, $_POST['inisial']);
    $set_inisial = ", inisial='$inisial2'";
  }

  if (!empty($password)) {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $sql = "UPDATE user SET USERNAME='$username2', PASWORD='$hashedPassword', NOWA='$nowa2', domain='$domain2', ticket_management_source='$effective_ticket_source'$set_inisial WHERE id='$id'";
  } else {
    $sql = "UPDATE user SET USERNAME='$username2', NOWA='$nowa2', domain='$domain2', ticket_management_source='$effective_ticket_source'$set_inisial WHERE id='$id'";
  }

  if (mysqli_query($conn, $sql)) {
    if ($AKSES !== 'ASSISTANT') {
      $owner_id = (int)$id;
      @mysqli_query($conn, "UPDATE user SET ticket_management_source='" . mysqli_real_escape_string($conn, $effective_ticket_source) . "' WHERE grup='" . $owner_id . "'");
    }
    save_dashboard_card_settings($username2, $new_dashboard_settings, $dashboard_card_defaults);
    echo "<script>alert('Profile berhasil diupdate. Silakan login ulang.'); window.location='logout.php';</script>";
    exit;
  } else {
    echo "<script>alert('Error update profile: " . $conn->error . "');</script>";
  }
}










        ?>
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <h2 class="mb-0"><i class="fas fa-user-cog me-2"></i>Profile and Account Settings</h2>
      </div>
      <button class="btn btn-light btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
    </div>
    <div class="card-body">
      <!-- Account Balance and Logo -->
      <div class="row mb-4">
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header bg-success text-white">
              <h5><i class="fas fa-wallet me-2"></i>Account Balance</h5>
              <small>Saldo akun Anda saat ini</small>
            </div>
              <?php if ($AKSES != "ASSISTANT") { ?>
            <div class="card-body text-center">
              <h3 class="text-success"><?php echo $saldo ?></h3>
              <a href="tambahsaldo.php" class="btn btn-warning">
                <i class="fas fa-plus me-1"></i>Tambah Saldo
              </a>
            </div>
             <?php } ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card shadow-sm">
            <div class="card-header bg-info text-white">
              <h5><i class="fas fa-image me-2"></i>Logo Product</h5>
              <small>Upload logo untuk produk Anda</small>
            </div>


            <div class="card-body text-center">
              <?php if ($AKSES != "ASSISTANT") { ?>
                <label for="fileInput" style="cursor: pointer;">
                  <img src="<?= $profile_picture ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 120px;">
                  <div class="mt-2 text-muted">📷 Klik untuk upload foto</div>
                </label>
                <form action="upload.php" method="post" enctype="multipart/form-data" style="display: none;">
                  <input type="file" name="profile_picture" id="fileInput" accept="image/png" onchange="this.form.submit()">
                </form>
                <?php if (file_exists(__DIR__ . "/../../dokumen/logo/profile-$ceknama.png")): ?>
                  <form action="proses/hapus_logo.php" method="post" class="mt-2">
                    <input type="hidden" name="ceknama" value="<?= htmlspecialchars($ceknama) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                      <i class="fas fa-trash me-1"></i>Hapus Logo
                    </button>
                  </form>
                <?php endif; ?>
              <?php } else { ?>
                <img src="<?= $profile_picture ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 120px;">
              <?php } ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Profile Form -->
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white">
         
          
            <?php if ($AKSES != "ASSISTANT") { ?>
               <h5><i class="fas fa-edit me-2"></i>Update Profile</h5>
          <small>Edit informasi profil Anda</small>
        </div>
        <div class="card-body">
              <form id="profileForm" action="" method="POST">
              <div class="mb-3">
                <label class="form-label"><i class="fas fa-tag me-1"></i>Inisial Product</label>
                <input type="text" name="inisial" class="form-control" value="<?php echo $inisial ?>" placeholder="Contoh: FQ atau NETQ" required>
              </div>
              <div class="mb-3">
              <label class="form-label"><i class="fas fa-user me-1"></i>Username</label>
              <input type="text" name="username" class="form-control" value="<?php echo $username ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-envelope me-1"></i>Email</label>
              <input type="email" name="domain" class="form-control" value="<?php echo $domain2 ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fab fa-whatsapp me-1"></i>WhatsApp</label>
              <input type="number" name="whatsapp" class="form-control" value="<?php echo $nowa ?>" placeholder="e.g., 6281234567890" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-lock me-1"></i>New Password</label>
              <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah">
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-lock me-1"></i>Confirm Password</label>
              <input type="password" id="confirmPassword" class="form-control" placeholder="Konfirmasi password baru">
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-random me-1"></i>Management Tiket</label>
              <?php if ($AKSES === 'USER') { ?>
              <input type="hidden" name="ticket_management_source" value="tiket_manager">
              <input type="text" class="form-control" value="Tiket Manager (Billing)" readonly>
              <small class="text-muted d-block mt-1">Status USER hanya dapat menggunakan Tiket Manager.</small>
              <?php } else { ?>
              <select name="ticket_management_source" class="form-select">
                <option value="tiket_manager" <?php echo $profile_ticket_source === 'tiket_manager' ? 'selected' : ''; ?>>Tiket Manager (Billing)</option>
                <option value="joblist" <?php echo $profile_ticket_source === 'joblist' ? 'selected' : ''; ?>>Joblist (Absensi)</option>
              </select>
              <?php } ?>
              <small class="text-muted d-block mt-1">Pengaturan ini berlaku untuk akun Anda dan seluruh ASSISTANT dalam grup Anda.</small>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-th-large me-1"></i>Pengaturan Card Dashboard</label>
              <small class="d-block text-muted mb-2">Centang card yang ingin ditampilkan di dashboard.</small>
              <div class="border rounded p-2" style="max-height: 230px; overflow-y: auto;">
                <?php foreach ($dashboard_card_labels as $card_key => $card_label): ?>
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="dashboard_cards[]" id="dashboard_card_owner_<?= md5($card_key) ?>" value="<?= htmlspecialchars($card_key) ?>" <?= !empty($dashboard_card_settings[$card_key]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dashboard_card_owner_<?= md5($card_key) ?>"><?= htmlspecialchars($card_label) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <button type="submit" name="save" class="btn btn-primary w-100">
              <i class="fas fa-save me-1"></i>Save Changes
            </button>
          </form>





            <?php }
            else
            {

?>
        <h5><i class="fas fa-edit me-2"></i>Update Profile ASSISTANT</h5>
          <small>Edit informasi profil Anda</small>
        </div>
        <div class="card-body">
             <form id="profileForm" action="" method="POST">
              <div class="mb-3">
              <label class="form-label"><i class="fas fa-user me-1"></i>Username</label>
              <input type="text" name="username" class="form-control" value="<?php echo $asistant_name ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-envelope me-1"></i>Email</label>
              <input type="email" name="domain" class="form-control" value="<?php echo $domain2 ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fab fa-whatsapp me-1"></i>WhatsApp</label>
              <input type="number" name="whatsapp" class="form-control" value="<?php echo $nowa ?>" placeholder="e.g., 6281234567890" required>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-lock me-1"></i>New Password</label>
              <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah">
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-lock me-1"></i>Confirm Password</label>
              <input type="password" id="confirmPassword" class="form-control" placeholder="Konfirmasi password baru">
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-random me-1"></i>Management Tiket</label>
              <input type="hidden" name="ticket_management_source" value="<?php echo htmlspecialchars($profile_ticket_source, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="text" class="form-control" value="<?php echo $profile_ticket_source === 'joblist' ? 'Joblist (ikut pengaturan pemilik)' : 'Tiket Manager (ikut pengaturan pemilik)'; ?>" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label"><i class="fas fa-th-large me-1"></i>Pengaturan Card Dashboard</label>
              <small class="d-block text-muted mb-2">Centang card yang ingin ditampilkan di dashboard.</small>
              <div class="border rounded p-2" style="max-height: 230px; overflow-y: auto;">
                <?php foreach ($dashboard_card_labels as $card_key => $card_label): ?>
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="dashboard_cards[]" id="dashboard_card_asst_<?= md5($card_key) ?>" value="<?= htmlspecialchars($card_key) ?>" <?= !empty($dashboard_card_settings[$card_key]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="dashboard_card_asst_<?= md5($card_key) ?>"><?= htmlspecialchars($card_label) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
 <button type="submit" name="save" class="btn btn-primary w-100">
              <i class="fas fa-save me-1"></i>Save Changes
            </button>
          </form>





            <?php






            } ?>
          
           
        </div>
      </div>

      <?php if ($AKSES != "ASSISTANT") { ?>
        <!-- Daftar ASSISTANT -->
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-warning text-white">
            <h5><i class="fas fa-users me-2"></i>Daftar ASSISTANT</h5>
            <small>Kelola akun assistant Anda</small>
          </div>
          <div class="card-body">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Username</th>
                  <th>WhatsApp</th>
                  <th>Role / Akses</th>
                  <th>Saldo</th>
                  <th>Server</th>
                  <th>Beban Bandwidth</th>
                  <th>Last Login</th>
                  <th>Created At</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
               $sql = "SELECT * FROM user WHERE STATUS ='ASSISTANT' AND grup ='$current_user_id' ORDER BY id DESC";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0):
                  while($row = $result->fetch_assoc()):
                    // Parse akses menu untuk menentukan role
                    $akses_array = json_decode($row['akses'], true);
                    if (!is_array($akses_array)) {
                      $akses_array = [];
                    }

                    // Tentukan role berdasarkan akses menu
                    $role_badge = '';
                    $role_title = '';

                    if (($row['assistant_role'] ?? '') === 'reseller') {
                      $role_badge = '<span class="badge bg-primary"><i class="fas fa-store"></i> RESELLER</span>';
                      $role_title = 'RESELLER';
                    } elseif (($row['assistant_role'] ?? '') === 'mitra_isp') {
                      $role_badge = '<span class="badge bg-info text-dark"><i class="fas fa-handshake"></i> MITRA ISP</span>';
                      $role_title = 'MITRA ISP';
                    } elseif (count($akses_array) === 1 && in_array('Ticket_manager', $akses_array, true)) {
                      $role_badge = '<span class="badge bg-danger"><i class="fas fa-wrench"></i> ASSISTANT Teknisi</span>';
                      $role_title = 'ASSISTANT Teknisi';
                    } elseif (in_array('Ticket_manager', $akses_array, true)) {
                      $role_badge = '<span class="badge bg-warning text-dark"><i class="fas fa-tools"></i> ASSISTANT Hybrid Teknis</span>';
                      $role_title = 'ASSISTANT Hybrid Teknis';
                    } elseif (in_array('Buat_Tiket', $akses_array) && !in_array('Ticket_manager', $akses_array)) {
                      $role_badge = '<span class="badge bg-info"><i class="fas fa-headset"></i> Support</span>';
                      $role_title = 'Support';
                    } else {
                      // Hitung berapa banyak menu akses teknis
                      $technical_menus = ['Vpn_Connection', 'Server_Area', 'Mapping_ODP', 'OLT', 'NMS', 'Insfrastruktur_maps'];
                      $tech_count = 0;
                      foreach ($technical_menus as $tm) {
                        if (in_array($tm, $akses_array)) $tech_count++;
                      }
                      
                      if ($tech_count > 0) {
                        $role_badge = '<span class="badge bg-warning text-dark"><i class="fas fa-cogs"></i> Tech Staff</span>';
                        $role_title = 'Tech Staff';
                      } else {
                        $role_badge = '<span class="badge bg-secondary"><i class="fas fa-user"></i> General</span>';
                        $role_title = 'General';
                      }
                    }
                    ?>
                    <tr>
                      <td><?= $row['id'] ?></td>
                      <td><?= $row['USERNAME'] ?></td>
                      <td><?= $row['NOWA'] ?></td>
                      <td>
                        <?= $role_badge ?>
                        <br>
                        <small class="text-muted d-block mt-1">
                          Menu: <?= count($akses_array) ?> akses
                        </small>
                      </td>
                      <td>Rp. <?= number_format($row['saldo'], 0, ',', '.') ?></td>
                      <td><?= $row['server'] ?><br><small><?= $row['area'] ?></small></td>
                      <td>
                        <?php if (in_array($row['assistant_role'] ?? '', ['reseller', 'mitra_isp'], true)):
                          $row_reseller_settings = [
                            'bw_cost' => (float)($row['reseller_bw_cost'] ?? 0),
                            'bw_bhp_uso' => (float)($row['reseller_bw_bhp_uso'] ?? 0),
                            'bw_ppn_percent' => (float)($row['reseller_bw_ppn_percent'] ?? 0),
                          ];
                        ?>
                          Rp. <?= number_format(reseller_bandwidth_burden($row_reseller_settings), 0, ',', '.') ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td><?php echo $row['last_login'] ? date('d/m/Y H:i', strtotime($row['last_login'])) : 'Belum login'; ?></td>
                      <td><?= $row['created_at'] ?></td>
                      <td>
                        <a href="?edit=<?= $row['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Edit</a>
                        <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin hapus user ini?')"><i class="fas fa-trash"></i> Hapus</a>
                      </td>
                    </tr>
                  <?php endwhile;
                else: ?>
                  <tr>
                    <td colspan="10" class="text-center text-muted">Tidak ada data assistant</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>



<?php
// Delete user
if(isset($_GET['delete'])){
    $user_id = $_GET['delete'];
    $sql = "DELETE FROM user WHERE id = $user_id";
    if ($conn->query($sql) === TRUE) {
        echo "<script>alert('User berhasil dihapus');window.location='user.php';</script>";
    } else {
        echo "<script>alert('Error: " . $conn->error . "');</script>";
    }
}


// Get servers
$server_result = $conn->query("SELECT DISTINCT server FROM user WHERE USERNAME='$username'");
$servers = [];
if ($server_result && $server_result->num_rows > 0) {
    while ($row = $server_result->fetch_assoc()) {
        $server_value = trim($row['server']);
        if ($server_value) {
            if (strpos($server_value, '[') !== false) {
                $arr = json_decode($server_value, true);
                if (is_array($arr)) $servers = array_merge($servers, $arr);
            } else {
                $servers[] = $server_value;
            }
        }
    }
}
$servers = array_unique(array_filter($servers));

// Profile picture (logo terpusat di dokumen/logo/, sejajar crm/)
$profile_picture_file = __DIR__ . "/../../dokumen/logo/profile-$ceknama.png";
$profile_picture = "/dokumen/logo/profile-$ceknama.png";
if (!file_exists($profile_picture_file) || strtolower(pathinfo($profile_picture_file, PATHINFO_EXTENSION)) !== 'png') {
    $profile_picture = "/dokumen/logo/logo.png";
}
?>


















        <!-- Form Add/Edit ASSISTANT -->
        <div class="card shadow-sm">
          <div class="card-header bg-primary text-white">
            <h5><i class="fas fa-user-plus me-2"></i><?= isset($_GET['edit']) ? 'Edit ASSISTANT' : 'Tambah ASSISTANT' ?></h5>
            <small>Kelola akun assistant</small>
          </div>
          <div class="card-body">
            <form method="POST">
              <?php if(isset($_GET['edit'])): ?>
                <input type="hidden" name="user_id" value="<?= $user_to_edit['id'] ?>">
              <?php endif; ?>
              <div class="row">
                <div class="col-md-6">
                  <div class="mb-3">
                    <label><i class="fas fa-user me-1"></i>Username</label>
                    <input type="text" name="username2" class="form-control" value="<?= isset($_GET['edit']) ? $user_to_edit['USERNAME'] : '' ?>" required>
                  </div>
                  <div class="mb-3">
                    <label><i class="fas fa-lock me-1"></i>Password <?= isset($_GET['edit']) ? '(Kosongkan jika tidak ingin mengubah)' : '' ?></label>
                    <input type="text" name="password2" class="form-control" placeholder="<?= isset($_GET['edit']) ? 'Kosongkan jika tidak ingin mengubah' : '' ?>" <?= !isset($_GET['edit']) ? 'required' : '' ?>>
                  </div>
                  <div class="mb-3">
                    <label><i class="fab fa-whatsapp me-1"></i>WhatsApp</label>
                    <input type="text" name="nowa2" class="form-control" value="<?= isset($_GET['edit']) ? $user_to_edit['NOWA'] : '' ?>" placeholder="Format: 628783876448" required>
                  </div>
                  <div class="mb-3">
                    <label><i class="fas fa-envelope me-1"></i>Email</label>
                    <input type="text" name="email2" class="form-control" value="<?= isset($_GET['edit']) ? $user_to_edit['domain'] : '' ?>" required>
                  </div>
                  <div class="mb-3">
                    <label><i class="fas fa-cog me-1"></i>Status</label>
                    <select name="status2" class="form-select" required>
                      <option value="ASSISTANT" <?= (isset($_GET['edit']) && $user_to_edit['STATUS'] == 'ASSISTANT') ? 'selected' : '' ?>>ASSISTANT</option>
                    </select>
                  </div>
                  <?php
                    $default_assistant_role = 'assistant';
                    if (isset($_GET['edit']) && !empty($user_to_edit['assistant_role'])) {
                      $default_assistant_role = $user_to_edit['assistant_role'];
                    } elseif (isset($_GET['edit']) && isset($user_akses) && is_array($user_akses)) {
                      $is_edit_teknisi = in_array('Ticket_manager', $user_akses, true);
                      $default_assistant_role = $is_edit_teknisi ? 'assistant_teknisi' : 'assistant';
                    }
                    if (!in_array($default_assistant_role, ['assistant', 'assistant_teknisi', 'reseller', 'mitra_isp'], true)) {
                      $default_assistant_role = 'assistant';
                    }
                  ?>
                  <div class="mb-3">
                    <label><i class="fas fa-user-shield me-1"></i>Tipe Assistant</label>
                    <select name="assistant_role" id="assistantRole" class="form-select" required>
                      <option value="assistant" <?= $default_assistant_role === 'assistant' ? 'selected' : '' ?>>ASSISTANT</option>
                      <option value="assistant_teknisi" <?= $default_assistant_role === 'assistant_teknisi' ? 'selected' : '' ?>>ASSISTANT TEKNISI</option>
                      <option value="reseller" <?= $default_assistant_role === 'reseller' ? 'selected' : '' ?>>RESELLER</option>
                      <option value="mitra_isp" <?= $default_assistant_role === 'mitra_isp' ? 'selected' : '' ?>>MITRA ISP</option>
                    </select>
                    <small class="text-muted d-block mt-1">ASSISTANT wajib menu Dasbor. ASSISTANT TEKNISI wajib menu Ticket Manager. RESELLER dan MITRA ISP hanya bisa melihat paket &amp; harga yang diizinkan akun utama (aturan sama persis).</small>
                  </div>

                  <?php $is_reseller_type_selected = in_array($default_assistant_role, ['reseller', 'mitra_isp'], true); ?>
                  <div id="resellerSettingsBlock" class="border rounded p-3 mb-3" style="<?= $is_reseller_type_selected ? '' : 'display:none;' ?>">
                    <h6 class="mb-3"><i class="fas fa-store me-1"></i>Pengaturan RESELLER / MITRA ISP</h6>
                    <div class="row">
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Biaya Bandwidth (Rp)</label>
                        <input type="number" step="0.01" min="0" name="reseller_bw_cost" class="form-control" value="<?= isset($_GET['edit']) ? htmlspecialchars($user_to_edit['reseller_bw_cost'] ?? 0) : '0' ?>">
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">BHP USO (Rp)</label>
                        <input type="number" step="0.01" min="0" name="reseller_bw_bhp_uso" class="form-control" value="<?= isset($_GET['edit']) ? htmlspecialchars($user_to_edit['reseller_bw_bhp_uso'] ?? 0) : '0' ?>">
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">PPN (%)</label>
                        <input type="number" step="0.01" min="0" name="reseller_bw_ppn_percent" class="form-control" value="<?= isset($_GET['edit']) ? htmlspecialchars($user_to_edit['reseller_bw_ppn_percent'] ?? 11) : '11' ?>">
                      </div>
                    </div>
                    <small class="text-muted d-block mb-2">Beban Tagihan Bandwidth = (Biaya Bandwidth + BHP USO) &times; (1 + PPN%). Muncul di kartu dashboard reseller.</small>

                    <div class="form-check form-switch mb-2">
                      <input class="form-check-input" type="checkbox" role="switch" id="resellerPriceFilterEnabled" name="reseller_price_filter_enabled" value="1" <?= (isset($_GET['edit']) && !empty($user_to_edit['reseller_price_filter_enabled'])) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="resellerPriceFilterEnabled">Aktifkan Filter Harga</label>
                    </div>

                    <?php if (isset($_GET['edit'])): ?>
                      <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#resellerPriceFilterModal">
                        <i class="fas fa-tags me-1"></i>Atur Filter Harga Paket
                      </button>
                    <?php else: ?>
                      <small class="text-muted d-block">Simpan akun RESELLER ini terlebih dahulu, lalu buka menu Edit untuk mengatur filter harga paket.</small>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="mb-3">
                    <label class="form-label"><i class="fas fa-tag me-1"></i>Inisial Product</label>
                    <input type="text" name="inisial" class="form-control" value="<?= $inisial  ?>" readonly placeholder="Contoh: FQ atau NETQ" required>
                  </div>
                  <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-server me-1"></i>Product Diizinkan</label>
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="selectAllServers">
                      <label class="form-check-label fw-bold" for="selectAllServers">Select All</label>
                    </div>
                    <div id="serverList">
                      <?php
                      $serverRows = [];
                      if ($current_user_id) {
                        $sql = "SELECT * FROM server WHERE user_id = $current_user_id";
                        $query = mysqli_query($conn, $sql);
                        while ($data = mysqli_fetch_array($query)) {
                          $serverRows[] = $data;
                        }
                      }
                      $user_servers = isset($user_servers) ? $user_servers : [];
                      // Tampilkan hanya satu checkbox per ID server
                      $uniqueServers = [];
                      foreach ($serverRows as $srv) {
                        $id = $srv['id'];
                        if (!isset($uniqueServers[$id])) {
                          $uniqueServers[$id] = $srv;
                        }
                      }
                      foreach ($uniqueServers as $id => $srv) {
                        $checked = in_array($id, $user_servers) ? 'checked' : '';
                        $brand = htmlspecialchars($srv['BRAND']);
                        $area = htmlspecialchars($srv['AREA']);
                        ?>
                        <div class="form-check">
                          <input type="checkbox" class="form-check-input server-checkbox" name='server[]' value="<?= htmlspecialchars($id) ?>" id="srv_<?= md5($id) ?>" <?= $checked ?> >
                          <label for="srv_<?= md5($id) ?>" class="form-check-label"><?= $brand ?><?= $area ? " (<span class='text-primary'>$area</span>)" : '' ?></label>
                        </div>
                      <?php } ?>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fab fa-whatsapp me-1"></i>Bot WA Diizinkan</label>
                    <small class="d-block text-muted mb-2">Kalau tidak ada bot yang dicentang, akun ini tetap bisa MEMBUAT bot sendiri di menu Whatsapp BOT -- bot buatannya sendiri otomatis privat (assistant lain tidak ikut lihat).</small>
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="selectAllBots">
                      <label class="form-check-label fw-bold" for="selectAllBots">Select All</label>
                    </div>
                    <div id="botList" class="border p-2 rounded" style="max-height: 220px; overflow-y: auto;">
                      <?php
                      $botwaRows = [];
                      if (!empty($ceknama)) {
                        $sqlBotwa = "SELECT id, namebot FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND (created_by_assistant IS NULL OR created_by_assistant = '') ORDER BY namebot ASC";
                        $queryBotwa = mysqli_query($conn, $sqlBotwa);
                        if ($queryBotwa) {
                          while ($rowBotwa = mysqli_fetch_assoc($queryBotwa)) {
                            $botwaRows[] = $rowBotwa;
                          }
                        }
                      }
                      $user_assigned_bots = isset($user_assigned_bots) ? $user_assigned_bots : [];
                      if (empty($botwaRows)) {
                        echo '<div class="text-muted small">Belum ada bot WA di akun ini.</div>';
                      }
                      foreach ($botwaRows as $botwaRow) {
                        $botId = (int)$botwaRow['id'];
                        $checked = in_array($botId, $user_assigned_bots) ? 'checked' : '';
                        $botLabel = htmlspecialchars($botwaRow['namebot']);
                        ?>
                        <div class="form-check">
                          <input type="checkbox" class="form-check-input bot-checkbox" name='assigned_bots[]' value="<?= $botId ?>" id="bot_<?= $botId ?>" <?= $checked ?>>
                          <label for="bot_<?= $botId ?>" class="form-check-label"><?= $botLabel ?></label>
                        </div>
                      <?php } ?>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fab fa-telegram me-1"></i>Bot Telegram Diizinkan</label>
                    <small class="d-block text-muted mb-2">Kalau tidak ada bot yang dicentang, akun ini tetap bisa MEMBUAT bot sendiri di menu Telegram Bot -- bot buatannya sendiri otomatis privat (assistant lain tidak ikut lihat).</small>
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="selectAllTelegramBots">
                      <label class="form-check-label fw-bold" for="selectAllTelegramBots">Select All</label>
                    </div>
                    <div id="telegramBotList" class="border p-2 rounded" style="max-height: 220px; overflow-y: auto;">
                      <?php
                      $bottelegramRows = [];
                      if (!empty($ceknama)) {
                        $sqlBottelegram = "SELECT id, namebot FROM bottelegram WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' AND (created_by_assistant IS NULL OR created_by_assistant = '') ORDER BY namebot ASC";
                        $queryBottelegram = mysqli_query($conn, $sqlBottelegram);
                        if ($queryBottelegram) {
                          while ($rowBottelegram = mysqli_fetch_assoc($queryBottelegram)) {
                            $bottelegramRows[] = $rowBottelegram;
                          }
                        }
                      }
                      $user_assigned_telegram_bots = isset($user_assigned_telegram_bots) ? $user_assigned_telegram_bots : [];
                      if (empty($bottelegramRows)) {
                        echo '<div class="text-muted small">Belum ada bot Telegram di akun ini.</div>';
                      }
                      foreach ($bottelegramRows as $bottelegramRow) {
                        $telegramBotId = (int)$bottelegramRow['id'];
                        $telegramChecked = in_array($telegramBotId, $user_assigned_telegram_bots) ? 'checked' : '';
                        $telegramBotLabel = htmlspecialchars($bottelegramRow['namebot']);
                        ?>
                        <div class="form-check">
                          <input type="checkbox" class="form-check-input telegram-bot-checkbox" name='assigned_telegram_bots[]' value="<?= $telegramBotId ?>" id="telegram_bot_<?= $telegramBotId ?>" <?= $telegramChecked ?>>
                          <label for="telegram_bot_<?= $telegramBotId ?>" class="form-check-label"><?= $telegramBotLabel ?></label>
                        </div>
                      <?php } ?>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Sengaja DIKELUARKAN dari row col-md-6 di atas -- section di bawah
                   ini (Hak Akses Menu s/d Tombol Individual) full-width supaya grid
                   multi-kolom (.perm-grid) punya cukup ruang, bukan numpuk vertikal
                   sempit di kolom kanan sementara kolom kiri kosong. -->
              <div class="alert alert-info small mb-3" style="border-left: 4px solid #0d6efd;">
                <strong><i class="fas fa-circle-info me-1"></i>5 Pengaturan di bawah ini urutannya dari PALING KASAR ke PALING HALUS:</strong>
                <ol class="mb-1 mt-1 ps-3">
                  <li><b>Hak Akses Menu</b> &mdash; boleh BUKA halaman ini atau tidak sama sekali (sidebar + URL langsung).</li>
                  <li><b>Card Dashboard ASSISTANT</b> &mdash; khusus tampilan widget/card di halaman Dashboard.</li>
                  <li><b>Tombol Masing-Masing Halaman</b> &mdash; sembunyikan SEMUA tombol dalam 1 halaman sekaligus (halamannya tetap bisa dibuka, cuma jadi read-only).</li>
                  <li><b>Group Tombol per Halaman</b> &mdash; sembunyikan SEKELOMPOK tombol terkait saja dalam 1 halaman (mis. cuma grup Export).</li>
                  <li><b>Tombol Individual</b> &mdash; sembunyikan 1 tombol spesifik saja (paling presisi, mis. cuma tombol Hapus).</li>
                </ol>
                <span class="text-muted">Aturan umum: ✅ <b>dicentang = TAMPIL/BOLEH</b> untuk assistant ini, ⬜ <b>tidak dicentang = DISEMBUNYIKAN/TIDAK BOLEH</b>. Kalau bingung, cukup atur #1 dan #3 saja -- #2, #4, #5 opsional untuk kontrol lebih detail.</span>
              </div>

              <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-list me-1"></i>Hak Akses Menu</label>
                    <small class="d-block text-muted mb-2">Menentukan halaman/menu APA SAJA yang boleh dibuka assistant ini. ✅ Dicentang = menu muncul di sidebar &amp; halamannya bisa dibuka. ⬜ Tidak dicentang = menu hilang dari sidebar DAN halamannya diblokir walau URL diketik langsung. Ini level PALING DASAR -- kalau menu di sini tidak dicentang, 4 pengaturan tombol di bawah untuk halaman itu jadi tidak relevan. Centang <b>Ticket Manager</b> saja jika akun ini khusus ASSISTANT Teknisi.</small>
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="selectAllMenus">
                      <label class="form-check-label fw-bold" for="selectAllMenus">Select All</label>
                    </div>
                    <div class="mb-2">
                      <input type="text" class="form-control form-control-sm" id="searchHakAksesMenu" placeholder="🔍 Cari menu...">
                    </div>
                    <div class="border p-2 rounded perm-grid" id="hakAksesMenuContainer" style="max-height: 300px; overflow-y: auto;">
                      <?php
                      // Menu Options dengan kategori
                      $menu_options = [
                        // NON-TEKNIS (Support & General)
                        'support' => [
                          'label' => '👥 SUPPORT & GENERAL',
                          'items' => [
                            'Dasbor' => 'Dasbor',
                            'Live_Chat' => 'Live Chat',
                            'Panduan' => 'Panduan',
                            'Notification_settings' => 'Notification Settings',
                            'Buat_Tiket' => 'Buat Tiket',
                          ]
                        ],
                        // TEKNIS (Technical Management)
                        'technical' => [
                          'label' => '⚙️ TEKNIS / JARINGAN (Technical)',
                          'items' => [
                            'Vpn_Connection' => 'Koneksi VPN',
                            'Server_Area' => 'Server Area',
                            'IP_Pool' => 'IP Pool',
                            'VLAN' => 'VLAN',
                            'Mapping_ODP' => 'Mapping ODP',
                            'OLT' => 'OLT',
                            'NMS' => 'NMS',
                            'Insfrastruktur_maps' => 'Infrastructure Maps',
                            'broadcast_info' => 'Broadcast Info',
                            'Informasi_Server_ACS' => 'Informasi Server ACS',
                          ]
                        ],
                        // TEKNIS (Customer Management)
                        'customer' => [
                          'label' => '👨‍💼 PELANGGAN / CUSTOMER (Technical)',
                          'items' => [
                            'Customer_PPPOE' => 'Customer PPPOE',
                            'Packages_Broadband' => 'Packages Broadband',
                            'Customer_Hotspot' => 'Customer Hotspot',
                            'Packages_Hotspot' => 'Packages Hotspot',
                            'Pelanggan_menunggak' => 'Pelanggan Menunggak',
                            'Pelanggan_berhenti' => 'Pelanggan Berhenti',
                          ]
                        ],
                        // TEKNIS (Financial)
                        'financial' => [
                          'label' => '💰 KEUANGAN / FINANCIAL (Technical)',
                          'items' => [
                            'Voucher_Generator' => 'Voucher Generator',
                            'Voucher_Bank' => 'Voucher Bank',
                            'Transaction' => 'Transaction',
                            'diskon' => 'Diskon',
                            'Tambahan_Biaya' => 'Tambahan Biaya',
                            'Laporan_pengeluaran' => 'Laporan Pengeluaran',
                            'Statistik' => 'Statistik dan Laporan',
                            // Payment_Setting SENGAJA dihapus dari sini -- halaman itu
                            // sekarang dikunci khusus akun utama (lihat paymentset.php
                            // & sidebar.php), tidak bisa diberikan ke assistant lewat
                            // checkbox manapun.
                            'Struk_setting' => 'Pengaturan Struk',
                            'Portal_setting' => 'Pengaturan Halaman Pelanggan',
                          ]
                        ],
                        // TEKNIS (Management)
                        'management' => [
                          'label' => '📊 MANAJEMEN / MANAGEMENT (Technical)',
                          'items' => [
                            'Provisioning_Joblist' => 'Provisioning Joblist',
                            'Mitra_accounts' => 'Mitra Accounts',
                            'Commission_setting' => 'Commission Setting',
                            'Pembayaran_Komisi' => 'Pembayaran Komisi',
                            'API_Intergrasi' => 'API Integration',
                            'log' => 'Log',
                            'Backup_Restore' => 'Backup & Restore',
                            'System_setting' => 'System Setting',
                          ]
                        ],
                        // TEKNIS (Ticketing)
                        'ticketing' => [
                          'label' => '🎫 TICKETING (Technical)',
                          'items' => [
                            'Ticket_monitoring' => 'Ticket Monitoring',
                            'Ticket_manager' => 'Ticket Manager',
                          ]
                        ],
                        // TEKNIS (Billing)
                        'billing' => [
                          'label' => '🌐 BILLING (Technical)',
                          'items' => [
                            'Login_hotspot_billing' => 'Login Hotspot Billing',
                            'broadband_login' => 'Broadband Login',
                            'Whatsapp_BOT' => 'Whatsapp BOT',
                            'Telegram_BOT' => 'Telegram Bot',
                          ]
                        ],
                      ];
                      
                      foreach($menu_options as $category => $data):
                        $categoryLabel = $data['label'];
                        $items = $data['items'];
                        
                        // Tentukan tipe badge berdasarkan kategori
                        $isTechnical = strpos($categoryLabel, 'TEKNIS') !== false;
                        $badgeClass = $isTechnical ? 'badge-technical' : 'badge-general';
                        $badgeText = $isTechnical ? 'TECHNICAL' : 'GENERAL';
                        $categoryClass = $isTechnical ? 'technical' : 'general';
                      ?>
                        <!-- Category Header -->
                        <div class="menu-category-header <?= $categoryClass ?>">
                          <div class="d-flex justify-content-between align-items-center">
                            <strong class="text-dark"><?= $categoryLabel ?></strong>
                            <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                          </div>
                        </div>
                        
                        <!-- Category Items -->
                        <div class="menu-category-items">
                          <?php foreach($items as $value => $label):
                            $checked = (isset($_GET['edit']) && in_array($value, $user_akses)) ? 'checked' : '';
                          ?>
                            <div class="form-check menu-item-checkbox">
                              <input type="checkbox" name="menu[]" value="<?= $value ?>" <?= $checked ?> class="form-check-input" id="menu_<?= md5($value) ?>">
                              <label class="form-check-label" for="menu_<?= md5($value) ?>">
                                <?= $label ?>
                              </label>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-th-large me-1"></i>Card Dashboard ASSISTANT</label>
                    <small class="d-block text-muted mb-2">Khusus halaman <b>Dashboard</b>: menentukan card/widget ringkasan apa saja yang tampil (grafik pembayaran, statistik, quick actions, dll). ✅ Dicentang = card tampil di Dashboard. ⬜ Tidak dicentang = card disembunyikan. Tidak memengaruhi halaman lain di luar Dashboard.</small>
                    <div class="d-flex gap-2 mb-2">
                      <button type="button" class="btn btn-outline-primary btn-sm" id="assistantDashboardSelectAll">Select All</button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" id="assistantDashboardClearAll">Clear All</button>
                    </div>
                    <div class="mb-2">
                      <input type="text" class="form-control form-control-sm" id="searchCardDashboard" placeholder="🔍 Cari card dashboard...">
                    </div>
                    <div class="border p-2 rounded perm-grid" id="cardDashboardContainer" style="max-height: 220px; overflow-y: auto;">
                      <?php
                        $dashboard_only_card_keys = [];
                        foreach ($dashboard_card_labels as $k => $v) {
                          if (strpos($k, 'buttons_') === 0 || strpos($k, 'btn_group_') === 0 || strpos($k, 'btn_') === 0) {
                            continue;
                          }
                          $dashboard_only_card_keys[$k] = $v;
                        }
                      ?>
                      <?php foreach ($dashboard_only_card_keys as $card_key => $card_label):
                        $checked_dashboard_card = !empty($assistant_dashboard_card_settings[$card_key]) ? 'checked' : '';
                      ?>
                        <div class="form-check">
                          <input type="checkbox" class="form-check-input dashboard-card-checkbox" name="assistant_dashboard_cards[]" value="<?= htmlspecialchars($card_key) ?>" id="assistant_dashboard_card_<?= md5($card_key) ?>" <?= $checked_dashboard_card ?>>
                          <label class="form-check-label" for="assistant_dashboard_card_<?= md5($card_key) ?>"><?= htmlspecialchars($card_label) ?></label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-button me-1"></i>Tombol Masing-Masing Halaman ASSISTANT</label>
                    <small class="d-block text-muted mb-2">Master switch PER HALAMAN: sembunyikan SEMUA tombol aksi (tambah/edit/hapus/import/export/dll) dalam 1 halaman sekaligus. ✅ Dicentang = tombol-tombol di halaman itu tetap tampil (ikut aturan Group/Individual di bawah). ⬜ Tidak dicentang = SEMUA tombol di halaman itu hilang, assistant cuma bisa LIHAT data saja (halamannya sendiri TETAP bisa dibuka -- beda dgn "Hak Akses Menu" di atas). Wajib dicentang dulu di sini supaya pengaturan Group Tombol &amp; Tombol Individual di bawah untuk halaman yang sama bisa berfungsi.</small>
                    <div class="d-flex gap-2 mb-2">
                      <button type="button" class="btn btn-outline-info btn-sm" id="assistantPageButtonsSelectAll">Select All Pages</button>
                      <button type="button" class="btn btn-outline-warning btn-sm" id="assistantPageButtonsClearAll">Clear All Pages</button>
                    </div>
                    <div class="mb-2">
                      <input type="text" class="form-control form-control-sm" id="searchTombolHalaman" placeholder="🔍 Cari tombol/halaman...">
                    </div>
                    <?php
                      $page_button_keys = [];
                      foreach ($dashboard_card_labels as $k => $v) {
                        if (strpos($k, 'buttons_') === 0 && strpos($k, 'buttons_semua') === false) {
                          $page_button_keys[$k] = $v;
                        }
                      }
                      $page_buttons_grouped = group_buttons_by_category_and_page($page_button_keys, $button_category_labels);
                    ?>
                    <?php if (!empty($page_buttons_grouped)): ?>
                      <div class="border p-2 rounded" id="tombolHalamanContainer" style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($page_buttons_grouped as $catData): ?>
                          <div class="btn-category-header">
                            <strong><?= htmlspecialchars($catData['label']) ?></strong>
                          </div>
                          <?php foreach ($catData['pages'] as $pageName => $pageItems): ?>
                            <div class="btn-page-subheader"><?= htmlspecialchars($pageName) ?></div>
                            <div class="btn-category-items">
                              <?php foreach ($pageItems as $btn_key => $btn_label):
                                $checked_page_button = !empty($assistant_dashboard_card_settings[$btn_key]) ? 'checked' : '';
                              ?>
                                <div class="form-check">
                                  <input type="checkbox" class="form-check-input page-buttons-checkbox" name="assistant_dashboard_cards[]" value="<?= htmlspecialchars($btn_key) ?>" id="page_button_<?= md5($btn_key) ?>" <?= $checked_page_button ?>>
                                  <label class="form-check-label" for="page_button_<?= md5($btn_key) ?>"><?= htmlspecialchars($btn_label) ?></label>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <p class="text-muted small">Tidak ada pengaturan per-halaman yang tersedia.</p>
                    <?php endif; ?>
                    <?php $checked_buttons_semua = !empty($assistant_dashboard_card_settings['buttons_semua_halaman']) ? 'checked' : ''; ?>
                    <div class="form-check mt-2 p-2 border rounded bg-light">
                      <input type="checkbox" class="form-check-input page-buttons-checkbox" name="assistant_dashboard_cards[]" value="buttons_semua_halaman" id="buttons_semua_halaman_master" <?= $checked_buttons_semua ?>>
                      <label class="form-check-label fw-bold" for="buttons_semua_halaman_master">Tombol Semua Halaman (Billing) &mdash; Master Switch</label>
                      <small class="d-block text-muted">Kalau ini TIDAK dicentang, SEMUA tombol di semua halaman akan disembunyikan untuk akun ini, apapun pengaturan lain di atas &amp; di 2 section berikutnya (Group Tombol &amp; Tombol Individual). Wajib dicentang agar tombol per-halaman/grup/individual berfungsi.</small>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-layer-group me-1"></i>Group Tombol per Halaman ASSISTANT</label>
                    <small class="d-block text-muted mb-2">Lebih detail dari "Tombol Masing-Masing Halaman" di atas: sembunyikan SEKELOMPOK tombol yang berhubungan dalam 1 halaman saja (mis. cuma grup "Export" atau "Quick Action"), tombol lain di halaman yang sama TETAP tampil. ✅ Dicentang = grup tombol itu tampil. ⬜ Tidak dicentang = grup tombol itu hilang. Berguna kalau assistant boleh pakai sebagian fitur saja, bukan semua tombol di halaman itu. Hanya tersedia utk halaman yang tombolnya sudah dikelompokkan (Dashboard, Server, OLT, Customer PPPOE).</small>
                    <div class="d-flex gap-2 mb-2">
                      <button type="button" class="btn btn-outline-success btn-sm" id="assistantGroupButtonsSelectAll">Select All Groups</button>
                      <button type="button" class="btn btn-outline-danger btn-sm" id="assistantGroupButtonsClearAll">Clear All Groups</button>
                    </div>
                    <div class="mb-2">
                      <input type="text" class="form-control form-control-sm" id="searchGroupTombol" placeholder="🔍 Cari group tombol...">
                    </div>
                    <?php
                      $group_button_keys = [];
                      foreach ($dashboard_card_labels as $k => $v) {
                        if (strpos($k, 'btn_group_') === 0) {
                          $group_button_keys[$k] = $v;
                        }
                      }
                      $group_buttons_grouped = group_buttons_by_category_and_page($group_button_keys, $button_category_labels);
                    ?>
                    <div class="border p-2 rounded" id="groupTombolContainer" style="max-height: 250px; overflow-y: auto;">
                      <?php foreach ($group_buttons_grouped as $catData): ?>
                        <div class="btn-category-header">
                          <strong><?= htmlspecialchars($catData['label']) ?></strong>
                        </div>
                        <?php foreach ($catData['pages'] as $pageName => $pageItems): ?>
                          <div class="btn-page-subheader"><?= htmlspecialchars($pageName) ?></div>
                          <div class="btn-category-items">
                            <?php foreach ($pageItems as $grp_key => $grp_label):
                              $checked_group_button = !empty($assistant_dashboard_card_settings[$grp_key]) ? 'checked' : '';
                            ?>
                              <div class="form-check">
                                <input type="checkbox" class="form-check-input group-buttons-checkbox" name="assistant_dashboard_cards[]" value="<?= htmlspecialchars($grp_key) ?>" id="group_button_<?= md5($grp_key) ?>" <?= $checked_group_button ?>>
                                <label class="form-check-label" for="group_button_<?= md5($grp_key) ?>"><?= htmlspecialchars($grp_label) ?></label>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="fw-bold"><i class="fas fa-sliders-h me-1"></i>Tombol Individual ASSISTANT</label>
                    <small class="d-block text-muted mb-2">Paling detail/presisi: sembunyikan 1 tombol TUNGGAL secara terpisah (mis. cuma tombol "Hapus" yang disembunyikan, tombol "Edit" di halaman yang sama tetap tampil). ✅ Dicentang = tombol itu tampil. ⬜ Tidak dicentang = tombol itu hilang. Pakai tombol "Hide Risky" di atas untuk langsung menyembunyikan semua tombol berisiko (hapus/reset/dll) sekaligus tanpa pilih satu-satu.</small>
                    <div class="d-flex gap-2 mb-2">
                      <button type="button" class="btn btn-outline-dark btn-sm" id="assistantIndividualButtonsSelectAll">Select All Individual</button>
                      <button type="button" class="btn btn-outline-secondary btn-sm" id="assistantIndividualButtonsClearAll">Clear All Individual</button>
                      <button type="button" class="btn btn-outline-danger btn-sm" id="assistantIndividualButtonsHideRisky">Hide Risky</button>
                      <button type="button" class="btn btn-outline-success btn-sm" id="assistantIndividualButtonsShowRisky">Show Risky</button>
                    </div>
                    <div class="mb-2">
                      <input type="text" class="form-control form-control-sm" id="searchIndividualTombol" placeholder="🔍 Cari tombol individual...">
                    </div>
                    <?php
                      $individual_button_keys = [];
                      foreach ($dashboard_card_labels as $k => $v) {
                        if (strpos($k, 'btn_') === 0 && strpos($k, 'btn_group_') !== 0) {
                          $individual_button_keys[$k] = $v;
                        }
                      }
                      $individual_buttons_grouped = group_buttons_by_category_and_page($individual_button_keys, $button_category_labels);
                    ?>
                    <div class="border p-2 rounded" id="individualTombolContainer" style="max-height: 250px; overflow-y: auto;">
                      <?php foreach ($individual_buttons_grouped as $catData): ?>
                        <div class="btn-category-header">
                          <strong><?= htmlspecialchars($catData['label']) ?></strong>
                        </div>
                        <?php foreach ($catData['pages'] as $pageName => $pageItems): ?>
                          <div class="btn-page-subheader"><?= htmlspecialchars($pageName) ?></div>
                          <div class="btn-category-items">
                            <?php foreach ($pageItems as $btn_key => $btn_label):
                              $checked_individual_button = !empty($assistant_dashboard_card_settings[$btn_key]) ? 'checked' : '';
                            ?>
                              <div class="form-check">
                                <input type="checkbox" class="form-check-input individual-buttons-checkbox" name="assistant_dashboard_cards[]" value="<?= htmlspecialchars($btn_key) ?>" id="individual_button_<?= md5($btn_key) ?>" <?= $checked_individual_button ?>>
                                <label class="form-check-label" for="individual_button_<?= md5($btn_key) ?>"><?= htmlspecialchars($btn_label) ?></label>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </div>
                  </div>
              <div class="text-end">
                <?php if(isset($_GET['edit'])): ?>
                  <button type="submit" name="edit_user" class="btn btn-warning"><i class="fas fa-save me-1"></i>Update User</button>
                  <a href="?" class="btn btn-secondary"><i class="fas fa-times me-1"></i>Batal</a>
                <?php else: ?>
                  <button type="submit" name="add_user" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Tambah User</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>

        <?php if (isset($_GET['edit']) && $user_to_edit && $user_to_edit['STATUS'] === 'ASSISTANT'):
          $reseller_area_names = reseller_resolve_area_names($conn, $user_to_edit['server'] ?? '');
          $reseller_broadband_rows = reseller_get_paket_rows($conn, $reseller_area_names, $user_to_edit['id'], 'broadband');
          $reseller_hotspot_rows = reseller_get_paket_rows($conn, $reseller_area_names, $user_to_edit['id'], 'hotspot');
        ?>
        <div class="modal fade" id="resellerPriceFilterModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
              <form method="POST" action="proses/save_reseller_price_filter.php" style="display: contents;">
                <input type="hidden" name="reseller_user_id" value="<?= (int)$user_to_edit['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title"><i class="fas fa-tags me-1"></i>Filter Harga Paket - <?= htmlspecialchars($user_to_edit['USERNAME']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p class="text-muted small">Centang paket yang boleh dilihat/dijual reseller ini. Kosongkan kolom harga khusus untuk memakai harga dasar.</p>
                  <?php if (empty($reseller_area_names)): ?>
                    <div class="alert alert-warning py-2">
                      <strong>Reseller ini belum memiliki akses Product/Server manapun.</strong>
                      Centang minimal satu <em>Product Diizinkan</em> di form sebelah kanan dan simpan dahulu, baru paket area tersebut akan muncul di sini untuk difilter.
                    </div>
                  <?php endif; ?>

                  <div class="mb-2">
                    <input type="text" class="form-control form-control-sm" id="resellerPaketSearch" placeholder="Cari nama paket untuk mempersempit daftar di bawah...">
                  </div>

                  <div class="d-flex gap-2 mb-2 flex-wrap">
                    <button type="button" class="btn btn-outline-success btn-sm" id="resellerPaketSelectAll">Select All Paket</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="resellerPaketClearAll">Clear All Paket</button>
                  </div>

                  <div class="d-flex gap-2 mb-3 flex-wrap align-items-center border rounded p-2 bg-light">
                    <label for="resellerMassHarga" class="mb-0 fw-bold small">Input Harga Massal:</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="resellerMassHarga" placeholder="Contoh: 75000" style="max-width: 180px;">
                    <button type="button" class="btn btn-primary btn-sm" id="resellerMassHargaApply">Terapkan ke Paket Tercentang</button>
                    <small class="text-muted d-block w-100">Isi harga di atas lalu klik tombol ini untuk menimpa kolom "Harga Khusus Reseller" pada SEMUA paket yang saat ini tercentang "Tampilkan" (broadband + hotspot sekaligus).</small>
                  </div>

                  <h6><i class="fas fa-wifi me-1"></i>Paket Broadband</h6>
                  <table class="table table-sm align-middle">
                    <thead>
                      <tr>
                        <th>Paket</th>
                        <th>Area</th>
                        <th class="text-end">Harga Dasar</th>
                        <th class="text-center">Tampilkan</th>
                        <th>Harga Khusus Reseller</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($reseller_broadband_rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted">Belum ada paket broadband</td></tr>
                      <?php endif; ?>
                      <?php foreach ($reseller_broadband_rows as $row): ?>
                        <tr class="reseller-paket-row">
                          <td>
                            <span class="reseller-paket-nama-text"><?= htmlspecialchars($row['nama']) ?></span>
                            <input type="hidden" name="broadband_nama[<?= (int)$row['id'] ?>]" value="<?= htmlspecialchars($row['nama']) ?>">
                          </td>
                          <td><?= htmlspecialchars($row['area']) ?></td>
                          <td class="text-end">Rp <?= number_format((float)$row['harga'], 0, ',', '.') ?></td>
                          <td class="text-center">
                            <input type="checkbox" class="reseller-paket-enabled-checkbox" name="broadband_enabled[<?= (int)$row['id'] ?>]" value="1" <?= $row['enabled'] ? 'checked' : '' ?>>
                          </td>
                          <td>
                            <input type="number" step="0.01" min="0" name="broadband_harga[<?= (int)$row['id'] ?>]" class="form-control form-control-sm reseller-paket-harga-input" value="<?= htmlspecialchars(($row['custom_harga'] !== '' && $row['custom_harga'] !== null) ? $row['custom_harga'] : $row['harga']) ?>">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>

                  <h6 class="mt-4"><i class="fas fa-broadcast-tower me-1"></i>Paket Hotspot</h6>
                  <table class="table table-sm align-middle">
                    <thead>
                      <tr>
                        <th>Paket</th>
                        <th>Area</th>
                        <th class="text-end">Harga Dasar</th>
                        <th class="text-center">Tampilkan</th>
                        <th>Harga Khusus Reseller</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($reseller_hotspot_rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted">Belum ada paket hotspot</td></tr>
                      <?php endif; ?>
                      <?php foreach ($reseller_hotspot_rows as $row): ?>
                        <tr class="reseller-paket-row">
                          <td>
                            <span class="reseller-paket-nama-text"><?= htmlspecialchars($row['nama']) ?></span>
                            <input type="hidden" name="hotspot_nama[<?= (int)$row['id'] ?>]" value="<?= htmlspecialchars($row['nama']) ?>">
                          </td>
                          <td><?= htmlspecialchars($row['area']) ?></td>
                          <td class="text-end">Rp <?= number_format((float)$row['harga'], 0, ',', '.') ?></td>
                          <td class="text-center">
                            <input type="checkbox" class="reseller-paket-enabled-checkbox" name="hotspot_enabled[<?= (int)$row['id'] ?>]" value="1" <?= $row['enabled'] ? 'checked' : '' ?>>
                          </td>
                          <td>
                            <input type="number" step="0.01" min="0" name="hotspot_harga[<?= (int)$row['id'] ?>]" class="form-control form-control-sm reseller-paket-harga-input" value="<?= htmlspecialchars(($row['custom_harga'] !== '' && $row['custom_harga'] !== null) ? $row['custom_harga'] : $row['harga']) ?>">
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                  <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Simpan Filter Harga</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php if (isset($_GET['filter_harga']) && $_GET['filter_harga'] === 'saved'): ?>
          <script>document.addEventListener('DOMContentLoaded', function(){ alert('Filter harga paket berhasil disimpan'); });</script>
        <?php endif; ?>
        <?php endif; ?>
      <?php } ?>
    </div>
  </div>
</div>

<script>
// Script untuk load area
document.querySelectorAll('.server-checkbox').forEach(cb => {
  cb.addEventListener('change', () => {
    const selected = Array.from(document.querySelectorAll('.server-checkbox:checked')).map(c => c.value);
    loadArea(selected);
  });
});

function loadArea(serverList) {
  const areaDiv = document.getElementById('areaContainer');
  areaDiv.innerHTML = '<p class="text-info">Memuat area...</p>';
  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'getdata/get_area_user.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function() {
    if (this.status === 200) {
      areaDiv.innerHTML = this.responseText;
    } else {
      areaDiv.innerHTML = '<p class="text-danger">Gagal memuat area.</p>';

    }
  };
  xhr.send('servers=' + encodeURIComponent(JSON.stringify(serverList)));
}

// Select All for Servers
document.getElementById('selectAllServers').addEventListener('change', function() {
  const isChecked = this.checked;
  document.querySelectorAll('.server-checkbox').forEach(cb => {
    cb.checked = isChecked;
    cb.dispatchEvent(new Event('change')); // Trigger change event to update area if needed
  });
});

// Update Select All Servers based on individual checkboxes
document.addEventListener('change', function(e) {
  if (e.target.classList.contains('server-checkbox')) {
    const allServers = document.querySelectorAll('.server-checkbox');
    const checkedServers = document.querySelectorAll('.server-checkbox:checked');
    document.getElementById('selectAllServers').checked = allServers.length === checkedServers.length;
  }
});

// Select All for Bots
var selectAllBotsEl = document.getElementById('selectAllBots');
if (selectAllBotsEl) {
  selectAllBotsEl.addEventListener('change', function() {
    const isChecked = this.checked;
    document.querySelectorAll('.bot-checkbox').forEach(cb => {
      cb.checked = isChecked;
    });
  });
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('bot-checkbox')) {
      const allBots = document.querySelectorAll('.bot-checkbox');
      const checkedBots = document.querySelectorAll('.bot-checkbox:checked');
      selectAllBotsEl.checked = allBots.length > 0 && allBots.length === checkedBots.length;
    }
  });
}

// Select All for Telegram Bots
var selectAllTelegramBotsEl = document.getElementById('selectAllTelegramBots');
if (selectAllTelegramBotsEl) {
  selectAllTelegramBotsEl.addEventListener('change', function() {
    const isChecked = this.checked;
    document.querySelectorAll('.telegram-bot-checkbox').forEach(cb => {
      cb.checked = isChecked;
    });
  });
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('telegram-bot-checkbox')) {
      const allTelegramBots = document.querySelectorAll('.telegram-bot-checkbox');
      const checkedTelegramBots = document.querySelectorAll('.telegram-bot-checkbox:checked');
      selectAllTelegramBotsEl.checked = allTelegramBots.length > 0 && allTelegramBots.length === checkedTelegramBots.length;
    }
  });
}

// Select All for Menus
document.getElementById('selectAllMenus').addEventListener('change', function() {
  const isChecked = this.checked;
  document.querySelectorAll('input[name="menu[]"]').forEach(cb => {
    if (cb.disabled) return;
    cb.checked = isChecked;
  });
  enforceAssistantRoleMenus();
});

function getMenuCheckboxByValue(value) {
  return document.querySelector('input[name="menu[]"][value="' + value + '"]');
}

function enforceAssistantRoleMenus() {
  const roleEl = document.getElementById('assistantRole');
  if (!roleEl) return;

  const role = roleEl.value;
  const dashboardCb = getMenuCheckboxByValue('Dasbor');
  const ticketManagerCb = getMenuCheckboxByValue('Ticket_manager');

  // reset lock
  document.querySelectorAll('input[name="menu[]"]').forEach(cb => {
    cb.disabled = false;
  });

  if (role === 'assistant_teknisi') {
    if (ticketManagerCb) {
      ticketManagerCb.checked = true;
      ticketManagerCb.disabled = true;
    }
    if (dashboardCb) {
      dashboardCb.checked = false;
    }
  } else {
    if (dashboardCb) {
      dashboardCb.checked = true;
      dashboardCb.disabled = true;
    }
    if (ticketManagerCb) {
      ticketManagerCb.disabled = false;
    }
  }
}

function toggleResellerSettingsBlock() {
  const roleEl = document.getElementById('assistantRole');
  const block = document.getElementById('resellerSettingsBlock');
  if (!roleEl || !block) return;
  block.style.display = (roleEl.value === 'reseller' || roleEl.value === 'mitra_isp') ? '' : 'none';
}

const assistantRoleEl = document.getElementById('assistantRole');
if (assistantRoleEl) {
  assistantRoleEl.addEventListener('change', enforceAssistantRoleMenus);
  assistantRoleEl.addEventListener('change', toggleResellerSettingsBlock);
}

function setAssistantDashboardCards(checkedState) {
  document.querySelectorAll('.dashboard-card-checkbox').forEach(cb => {
    cb.checked = checkedState;
  });
}

const assistantDashboardSelectAllBtn = document.getElementById('assistantDashboardSelectAll');
if (assistantDashboardSelectAllBtn) {
  assistantDashboardSelectAllBtn.addEventListener('click', function() {
    setAssistantDashboardCards(true);
  });
}

const assistantDashboardClearAllBtn = document.getElementById('assistantDashboardClearAll');
if (assistantDashboardClearAllBtn) {
  assistantDashboardClearAllBtn.addEventListener('click', function() {
    setAssistantDashboardCards(false);
  });
}

// Select All for Per-Page Buttons
function setAssistantPageButtons(checkedState) {
  document.querySelectorAll('.page-buttons-checkbox').forEach(cb => {
    cb.checked = checkedState;
  });
}

// Select All for reseller price-filter package checkboxes (broadband + hotspot)
function setResellerPaketEnabled(checkedState) {
  document.querySelectorAll('.reseller-paket-enabled-checkbox').forEach(cb => {
    cb.checked = checkedState;
  });
}

const resellerPaketSelectAllBtn = document.getElementById('resellerPaketSelectAll');
if (resellerPaketSelectAllBtn) {
  resellerPaketSelectAllBtn.addEventListener('click', function() {
    setResellerPaketEnabled(true);
  });
}

const resellerPaketClearAllBtn = document.getElementById('resellerPaketClearAll');
if (resellerPaketClearAllBtn) {
  resellerPaketClearAllBtn.addEventListener('click', function() {
    setResellerPaketEnabled(false);
  });
}

// Cari/filter nama paket (client-side) di modal filter harga reseller
const resellerPaketSearchEl = document.getElementById('resellerPaketSearch');
if (resellerPaketSearchEl) {
  resellerPaketSearchEl.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.reseller-paket-row').forEach(row => {
      const nameEl = row.querySelector('.reseller-paket-nama-text');
      const name = nameEl ? nameEl.textContent.toLowerCase() : '';
      row.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
    });
  });
}

// Input harga massal: terapkan satu angka ke semua paket yang sedang tercentang "Tampilkan"
const resellerMassHargaApplyBtn = document.getElementById('resellerMassHargaApply');
if (resellerMassHargaApplyBtn) {
  resellerMassHargaApplyBtn.addEventListener('click', function() {
    const massHargaEl = document.getElementById('resellerMassHarga');
    const val = massHargaEl ? massHargaEl.value : '';
    if (val === '' || isNaN(parseFloat(val))) {
      alert('Masukkan angka harga yang valid terlebih dahulu.');
      return;
    }
    let count = 0;
    document.querySelectorAll('.reseller-paket-enabled-checkbox:checked').forEach(cb => {
      const row = cb.closest('tr');
      if (!row) return;
      const priceInput = row.querySelector('.reseller-paket-harga-input');
      if (priceInput) {
        priceInput.value = val;
        count++;
      }
    });
    if (count === 0) {
      alert('Tidak ada paket yang tercentang "Tampilkan". Centang dulu paket yang ingin diberi harga massal.');
    }
  });
}

const assistantPageButtonsSelectAllBtn = document.getElementById('assistantPageButtonsSelectAll');
if (assistantPageButtonsSelectAllBtn) {
  assistantPageButtonsSelectAllBtn.addEventListener('click', function() {
    setAssistantPageButtons(true);
  });
}

const assistantPageButtonsClearAllBtn = document.getElementById('assistantPageButtonsClearAll');
if (assistantPageButtonsClearAllBtn) {
  assistantPageButtonsClearAllBtn.addEventListener('click', function() {
    setAssistantPageButtons(false);
  });
}

// Select All for Group Buttons
function setAssistantGroupButtons(checkedState) {
  document.querySelectorAll('.group-buttons-checkbox').forEach(cb => {
    cb.checked = checkedState;
  });
}

const assistantGroupButtonsSelectAllBtn = document.getElementById('assistantGroupButtonsSelectAll');
if (assistantGroupButtonsSelectAllBtn) {
  assistantGroupButtonsSelectAllBtn.addEventListener('click', function() {
    setAssistantGroupButtons(true);
  });
}

const assistantGroupButtonsClearAllBtn = document.getElementById('assistantGroupButtonsClearAll');
if (assistantGroupButtonsClearAllBtn) {
  assistantGroupButtonsClearAllBtn.addEventListener('click', function() {
    setAssistantGroupButtons(false);
  });
}

// Select All for Individual Buttons
function setAssistantIndividualButtons(checkedState) {
  document.querySelectorAll('.individual-buttons-checkbox').forEach(cb => {
    cb.checked = checkedState;
  });
}

const assistantIndividualButtonsSelectAllBtn = document.getElementById('assistantIndividualButtonsSelectAll');
if (assistantIndividualButtonsSelectAllBtn) {
  assistantIndividualButtonsSelectAllBtn.addEventListener('click', function() {
    setAssistantIndividualButtons(true);
  });
}

const assistantIndividualButtonsClearAllBtn = document.getElementById('assistantIndividualButtonsClearAll');
if (assistantIndividualButtonsClearAllBtn) {
  assistantIndividualButtonsClearAllBtn.addEventListener('click', function() {
    setAssistantIndividualButtons(false);
  });
}

const riskyButtonKeys = [
  'btn_vpn_delete_user',
  'btn_odp_delete',
  'btn_tiket_update',
  'btn_notif_modal_save',
  'btn_dash_export_pdf'
];

function setRiskyButtons(checkedState) {
  document.querySelectorAll('.individual-buttons-checkbox').forEach(cb => {
    if (riskyButtonKeys.includes(cb.value)) {
      cb.checked = checkedState;
    }
  });
}

const assistantIndividualButtonsHideRiskyBtn = document.getElementById('assistantIndividualButtonsHideRisky');
if (assistantIndividualButtonsHideRiskyBtn) {
  assistantIndividualButtonsHideRiskyBtn.addEventListener('click', function() {
    setRiskyButtons(false);
  });
}

const assistantIndividualButtonsShowRiskyBtn = document.getElementById('assistantIndividualButtonsShowRisky');
if (assistantIndividualButtonsShowRiskyBtn) {
  assistantIndividualButtonsShowRiskyBtn.addEventListener('click', function() {
    setRiskyButtons(true);
  });
}

// Initialize Select All status on page load
document.addEventListener('DOMContentLoaded', function() {
  // Trigger change for servers
  document.querySelectorAll('.server-checkbox').forEach(cb => {
    cb.dispatchEvent(new Event('change'));
  });
  // Trigger change for menus
  document.querySelectorAll('input[name="menu[]"]').forEach(cb => {
    cb.dispatchEvent(new Event('change'));
  });

  // Enforce role-based menu defaults
  enforceAssistantRoleMenus();
});

// ============ Kolom pencarian utk 5 daftar checkbox panjang ============
// Filter .form-check berdasarkan teks labelnya, lalu sembunyikan header/
// sub-header kategori kalau SEMUA isinya ikut ke-filter habis (supaya tidak
// nyisa header kosong pas hasil pencarian sedikit).
function permSearchElIsHeader(el) {
  return el.classList.contains('menu-category-header') || el.classList.contains('btn-category-header') || el.classList.contains('btn-page-subheader');
}
function permSearchElLevel(el) {
  if (el.classList.contains('btn-category-header') || el.classList.contains('menu-category-header')) return 1;
  if (el.classList.contains('btn-page-subheader')) return 2;
  return 0;
}
function setupPermSearch(inputId, containerId) {
  const input = document.getElementById(inputId);
  const container = document.getElementById(containerId);
  if (!input || !container) return;
  input.addEventListener('input', function() {
    const q = input.value.trim().toLowerCase();
    container.querySelectorAll('.form-check').forEach(function(fc) {
      const label = fc.querySelector('.form-check-label');
      const text = label ? label.textContent.toLowerCase() : '';
      fc.style.display = (q === '' || text.indexOf(q) !== -1) ? '' : 'none';
    });
    container.querySelectorAll('.menu-category-header, .btn-category-header, .btn-page-subheader').forEach(function(h) {
      const level = permSearchElLevel(h);
      let el = h.nextElementSibling;
      let hasVisible = false;
      while (el) {
        if (permSearchElIsHeader(el) && permSearchElLevel(el) <= level) break;
        if (!permSearchElIsHeader(el)) {
          el.querySelectorAll('.form-check').forEach(function(c) {
            if (c.style.display !== 'none') hasVisible = true;
          });
          if (el.classList.contains('form-check') && el.style.display !== 'none') hasVisible = true;
        }
        el = el.nextElementSibling;
      }
      h.style.display = (q === '' || hasVisible) ? '' : 'none';
    });
  });
}
setupPermSearch('searchHakAksesMenu', 'hakAksesMenuContainer');
setupPermSearch('searchCardDashboard', 'cardDashboardContainer');
setupPermSearch('searchTombolHalaman', 'tombolHalamanContainer');
setupPermSearch('searchGroupTombol', 'groupTombolContainer');
setupPermSearch('searchIndividualTombol', 'individualTombolContainer');
</script>


<?php require 'footer.php'; ?>