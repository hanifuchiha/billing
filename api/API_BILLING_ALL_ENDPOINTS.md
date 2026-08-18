# Dokumentasi Lengkap API Billing (Semua Fungsi)

**Base URL:**  
https://quenbytekniksejahtera.com/crm/billing/api/

---

## 1. Autentikasi

### Login
- **Endpoint:** `POST /login.php`
- **Body:**
  ```json
  {
    "username": "FIBERQ",
    "password": "Deltaiman@92"
  }
  ```
- **Catatan:**
  Simpan cookie session untuk akses endpoint lain.

---

## 2. Data Master & CRUD

| Endpoint                | Method         | Fungsi                        | Parameter                |
|-------------------------|---------------|-------------------------------|--------------------------|
| /pelanggan.php          | GET           | List semua pelanggan          | -                        |
| /tagihan.php            | GET           | List semua tagihan            | -                        |
| /transaksi.php          | GET           | List semua transaksi          | -                        |
| /paket.php              | GET           | List semua paket layanan      | -                        |
| /server.php             | GET           | List semua server             | -                        |
| /server_area.php        | GET/POST/PUT/DELETE | CRUD area server        | id, PEMILIK, AREA        |
| /topup.php              | GET           | Data topup                    | -                        |
| /panduan.php            | GET           | Data panduan                  | -                        |
| /log.php                | GET           | Log aktivitas                 | -                        |

---

## 3. Utility & Monitoring

| Endpoint                | Method | Fungsi                                    | Parameter         |
|-------------------------|--------|-------------------------------------------|-------------------|
| /dashboard.php          | GET    | Statistik utama                           | -                 |
| /grafik_transaksi.php   | GET    | Grafik transaksi bulanan                  | tahun             |
| /monitoring.php         | GET    | Data monitoring tiket                     | -                 |
| /tiket_manager.php      | GET    | Data tiket                                | -                 |
| /livechat.php           | GET    | Data livechat                             | -                 |
| /notifikasi.php         | GET    | Data notifikasi                           | -                 |
| /upload.php             | GET    | Data upload                               | -                 |

---

## 4. Infrastruktur

| Endpoint                | Method | Fungsi                                    | Parameter         |
|-------------------------|--------|-------------------------------------------|-------------------|
| /odp.php                | GET    | Data ODP                                  | -                 |
| /olt.php                | GET    | Data OLT                                  | -                 |
| /vpn.php                | GET    | Data VPN                                  | -                 |
| /pool.php               | GET    | Data IP Pool                              | -                 |
| /nms.php                | GET    | Data Network Management System            | -                 |

---

## 5. Proxy & Proses Otomatis

### getdata.php
- **Endpoint:** `/getdata.php?file=<file>&param=...`
- **Fungsi:** Mendapatkan data dinamis dari file di folder getdata.
- **Contoh file:**
  - get_chart_data.php
  - get_customer.php
  - get_daily_transaction.php
  - get_log.php
  - get_mikrotik_traffic.php
  - get_odp.php
  - get_packages.php
  - get_status_transaksi.php
  - getonlinecustomer.php
  - getonlinehotspot.php
  - get-active-users.php
  - get-active-pppoe-hotspot.php
  - get-total-active.php
  - gettxrx.php
  - gettxrx_simple.php
  - get_area.php
  - get_area_id.php
  - get_area_user.php
  - get_pelanggan_berhenti.php
  - get_interface.php
  - get_odp_by_server.php
  - get_odp_id.php
  - get_packages_hotspot.php
  - get_packages_id.php
  - get_packages_ratelimit.php
  - get_packages_uptime.php
  - getonulist.php
  - count_tiket.php
  - dataload.php
  - serverload.php
  - scan_unregistered_pppoe.php
  - readontcdata.php
  - readonthioso.php
  - readontzte.php
  - zte_onu.php
  - zte_optical.php
  - cek_radius.php
  - ping.php

### proses.php
- **Endpoint:** `/proses.php?file=<file>&param=...`
- **Fungsi:** Menjalankan proses otomatis dari file di folder proses.
- **Contoh file:**
  - addserver.php
  - editserver.php
  - deleteserver.php
  - addcustomer.php
  - editcustomer.php
  - deletecustomer.php
  - addodp.php
  - editodp.php
  - deleteodp.php
  - addpackagespppoe.php
  - editpackagespppoe.php
  - deletepackages.php
  - addpackageshotspot.php
  - editpackageshotspot.php
  - deletepackageshotspot.php
  - addpppoeserver.php
  - addhotspotserver.php
  - aktifkan_server.php
  - apply_pool.php
  - delete_pool.php
  - save_biaya_tambahan_pelanggan.php
  - delete_biaya_tambahan_pelanggan.php
  - save_diskon_pelanggan.php
  - delete_diskon_pelanggan.php
  - save_diskon_menunggak_massal.php
  - broadcast_berhenti.php
  - broadcast_berhenti_background.php
  - buat_tiket_menunggak_massal.php
  - notif_gangguan.php
  - notif_manual.php
  - notif_menunggak_manual.php
  - sendinvoice.php
  - simpannotif.php
  - update_timer.php
  - verify_password.php
  - hapusvpn.php
  - hapus_logo.php
  - hapus_pelanggan_berhenti.php
  - hapus_profile.php
  - import_server.php
  - import_paket.php
  - import_hotspot.php
  - import_odp_excel.php
  - import_odp_kmz.php
  - export_server.php
  - export_packages.php
  - export_hotspot.php
  - export_odp_excel.php
  - export_odp_kml.php
  - manual_generate_invoice.php
  - save_to_db.php
  - konfirmasi.php
  - belivpn.php
  - ontremot.php
  - radius.php
  - routeros_api.class.php
  - check_server_dependency.php
  - clear_broadcast_status.php
  - get_broadcast_logs.php

### mikrotik.php
- **Endpoint:** `/mikrotik.php?action=<action>&param=...`
- **Fungsi:** Proxy proses ke Mikrotik (reboot, update, cek, dsb).

---

## 6. Contoh Penggunaan

### a. Login dan Ambil Data Pelanggan
```bash
curl -c cookie.txt -X POST -H "Content-Type: application/json" -d '{"username":"FIBERQ","password":"Deltaiman@92"}' https://quenbytekniksejahtera.com/crm/billing/api/login.php
curl -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/pelanggan.php
```

### b. Ambil Data Tagihan
```bash
curl -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/tagihan.php
```

### c. Proses Otomatis (misal: tambah server)
```bash
curl -b cookie.txt 'https://quenbytekniksejahtera.com/crm/billing/api/proses.php?file=addserver.php&nama=SERVER1&ip=192.168.1.1'
```

### d. Ambil Data ODP
```bash
curl -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/odp.php
```

### e. Proxy getdata (misal: get_chart_data)
```bash
curl -b cookie.txt 'https://quenbytekniksejahtera.com/crm/billing/api/getdata.php?file=get_chart_data.php&bulan=4&tahun=2026'
```

---

## 7. Error Handling

- Semua response sukses berupa JSON.
- Jika error, response akan mengandung key `error`.
- Untuk proses/getdata, jika output bukan JSON, akan dibungkus dalam `{ "result": "..." }`.

---

## 8. Tips & Best Practice

- Selalu gunakan HTTPS.
- Simpan session/cookie setelah login untuk akses endpoint lain.
- Cek response JSON untuk status/error.
- Gunakan parameter sesuai kebutuhan file proses/getdata.
- Untuk integrasi Mikrotik/ACS, gunakan endpoint proxy yang sudah disediakan.

---

## 9. Akun Tes

- Username: `FIBERQ`
- Password: `Deltaiman@92`

---

## 10. Kontak & Bantuan

Jika butuh bantuan lebih lanjut, silakan hubungi admin/support.

---

Dengan dokumentasi ini, Anda bisa mendapatkan data apapun yang tersedia di web melalui API, selama endpoint dan parameter sesuai dengan daftar di atas. Jika ada endpoint baru di folder billing, cukup tambahkan ke tabel di atas dengan pola yang sama.

---

## 11. Penjelasan Parameter & Otorisasi

- Untuk endpoint yang membutuhkan autentikasi/session, login terlebih dahulu dan simpan cookie (`-c cookie.txt`), lalu gunakan cookie tersebut (`-b cookie.txt`) pada request berikutnya.
- Parameter GET dikirim di URL, parameter POST/PUT/DELETE bisa dikirim dalam body (JSON atau form-data, sesuai endpoint).
- Untuk proses.php dan getdata.php, parameter dikirim via query string.

---

## 12. Contoh CRUD Lengkap

### Server Area

- **GET** semua area:
  ```bash
  curl -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/server_area.php
  ```
- **POST** tambah area:
  ```bash
  curl -X POST -H "Content-Type: application/json" -d '{"PEMILIK":"NAMA","AREA":"AREA"}' -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/server_area.php
  ```
- **PUT** edit area:
  ```bash
  curl -X PUT -d 'id=1&PEMILIK=NAMA_BARU&AREA=AREA_BARU' -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/server_area.php
  ```
- **DELETE** hapus area:
  ```bash
  curl -X DELETE -d 'id=1' -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/server_area.php
  ```

### Pelanggan (jika tersedia endpoint POST/PUT/DELETE)
- **POST** tambah pelanggan, **PUT** edit, **DELETE** hapus, pola sama seperti di atas.

---

## 13. Struktur Response

- **Sukses:**
  ```json
  { "success": true, "data": [ ... ] }
  ```
- **Error:**
  ```json
  { "error": "Pesan error" }
  ```
- Untuk proses/getdata, jika output bukan JSON:
  ```json
  { "result": "output" }
  ```

---

## 14. Troubleshooting

- Jika response `{ "error": ... }`, cek parameter dan session/cookie.
- Pastikan endpoint dan parameter sesuai dokumentasi.
- Jika "Method not allowed", cek method (GET/POST/PUT/DELETE) sudah benar.
- Jika "File not allowed", cek nama file pada proses/getdata sudah benar dan diizinkan.

---

## 15. Update Endpoint

- Jika ada file baru di folder `api/`, `getdata/`, atau `proses/`, tambahkan ke tabel endpoint dan daftar file pada dokumentasi ini.
- Untuk endpoint baru, gunakan pola dokumentasi yang sama agar konsisten.

---

## 16. Reseller / Mitra ISP, Manual Active, Upload Logo, Export (fitur terbaru)

Semua endpoint di section ini pakai auth standar `_bootstrap.php` (session aktif -> username+password
-> `?key=<API_KEY>` / body `key`/`api_key`), sama seperti `pelanggan_berhenti.php`/`statistik.php`.

### 16.1 Pengaturan RESELLER / MITRA ISP -- `user_assistant.php`

Field reseller kini ikut di setiap response `user_assistant.php` (GET/POST/PUT), dalam key `reseller`:

```json
"reseller": {
  "assistant_role": "reseller",       // assistant | assistant_teknisi | reseller | mitra_isp
  "price_filter_enabled": true,       // filter harga custom paket aktif?
  "cost_scheme": "omset_percent",     // "bandwidth" atau "omset_percent"
  "bw_cost": 0, "bw_ppn_percent": 11, "bw_bhp_uso": 0,   // dipakai kalau cost_scheme = bandwidth
  "omset_percent": 5.5,               // dipakai kalau cost_scheme = omset_percent
  "is_reseller": true,
  "current_burden": 275000            // beban terhitung (Rp), sudah sesuai skema yang dipilih
}
```

- **GET** `/user_assistant.php` atau `/user_assistant.php?id=<id>` -> owner-only, list/detail sub-akun ASSISTANT termasuk blok `reseller` di atas.
- **POST** `/user_assistant.php` -> tambah sub-akun baru, body boleh sertakan `reseller: {...}` (semua field opsional, default `assistant_role="assistant"`).
- **PUT** `/user_assistant.php` -> body `{ "id": 123, "reseller": {...} }` untuk ubah skema/role/filter harga; kalau salah satu field reseller dikirim, ke-7 kolomnya diupdate sekaligus (samakan dengan form Edit ASSISTANT di web).

### 16.2 Filter Harga Custom Paket -- `reseller_paket_price.php` (baru)

- **GET** `/reseller_paket_price.php?reseller_user_id=123` -> daftar paket broadband+hotspot di area reseller ini, dengan status `enabled`/`custom_harga` yang sudah tersimpan.
- **POST/PUT** `/reseller_paket_price.php` -> body:
  ```json
  {
    "reseller_user_id": 123,
    "items": [
      { "paket_type": "broadband", "paket_id": 45, "paket_nama": "Home 20Mbps", "enabled": true, "custom_harga": 150000 }
    ]
  }
  ```

### 16.3 Manual Active -- `manual_active.php` (baru)

- **POST** `/manual_active.php` (multipart/form-data, SAMA PERSIS dengan field form Manual Active di web: `id`, `NAMA`, `IDPEL`, `EMAIL`, `NOWA`, `PAKET`, `PEMILIK`, `AREA`, `metode_bayar`, `only_activate_without_transaksi`, `periode_month`, `periode_year`, file `bukti_pembayaran`).
- Endpoint ini adalah *bridge* langsung ke `proses/activecustomer.php` (bukan reimplementasi terpisah) -- otomatis connect Mikrotik/RADIUS + kirim notif WA persis seperti tombol Manual Active di web, termasuk mengikuti perbaikan terbaru (perilaku disamakan dengan callback payment gateway, tidak lagi ada gating periode).

### 16.4 Upload Logo Billing -- `upload_logo.php` (baru)

- **POST** `/upload_logo.php` (multipart/form-data, field file: `profile_picture`, JPG/PNG maks 2MB).
- ASSISTANT hanya bisa upload logo **sendiri** kalau owner sudah aktifkan toggle *"Tombol: Upload/Hapus Logo Billing Sendiri"* di Pengaturan User; kalau belum, request ditolak (403) -- tidak pernah diam-diam menimpa logo owner.
- Warna tema (`--primary-color`/`--secondary-color`) otomatis dihitung & disimpan dari logo baru, sama seperti alur upload di web.

### 16.5 Export dengan Harga Reseller -- `backup.php?type=billing_core_data`

- Sudah ada sebelumnya, sekarang harga di `include=pelanggan` dan `include=transaksi` otomatis mengikuti harga custom reseller (kalau caller adalah akun reseller/mitra ISP dengan filter harga aktif) -- tidak perlu parameter tambahan, otomatis terdeteksi dari akun yang login/API key yang dipakai.
- Contoh: `GET /backup.php?type=billing_core_data&include=pelanggan,transaksi`

---
