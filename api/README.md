# Dokumentasi Super Lengkap API Billing

Base URL: **https://quenbytekniksejahtera.com/crm/billing/api/**

---

## 1. Autentikasi & Login

### Endpoint
`POST /login.php`

### Request Body (JSON)
```
{
  "username": "FIBERQ",
  "password": "Deltaiman@92"
}
```

### Contoh Curl
```
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"Deltaiman@92"}' \
  https://quenbytekniksejahtera.com/crm/billing/api/login.php
```

### Response Sukses
```
{
  "success": true,
  "user": { ... },
  "akses_menu_login": [ ... ],
  "landing_page": "dashboard.php",
  "session": { ... }
}
```

### Catatan
- Gunakan endpoint ini untuk login dan mendapatkan session/cookie.
- Wajib login sebelum akses endpoint yang membutuhkan autentikasi.

---

## 2. Endpoint Data Master & CRUD

### Pelanggan
- **GET /pelanggan.php** — List semua pelanggan
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/pelanggan.php`
  - Response: `{ "data": [ { ... } ] }`

### Tagihan
- **GET /tagihan.php** — List semua tagihan
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/tagihan.php`
  - Response: `{ "data": [ { ... } ] }`

### Transaksi
- **GET /transaksi.php** — List semua transaksi
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/transaksi.php`
  - Response: `{ "data": [ { ... } ] }`

### Paket Layanan
- **GET /paket.php** — List semua paket
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/paket.php`
  - Response: `{ "data": [ { ... } ] }`

### Server
- **GET /server.php** — List semua server
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/server.php`
  - Response: `{ "data": [ { ... } ] }`

### Server Area (CRUD)
- **GET /server_area.php** — List area server
- **POST /server_area.php** — Tambah area server (body: JSON {PEMILIK, AREA})
- **PUT /server_area.php** — Edit area server (body: id, PEMILIK, AREA)
- **DELETE /server_area.php** — Hapus area server (body: id)

---

## 3. Endpoint Utility & Monitoring

### Dashboard
- **GET /dashboard.php** — Statistik utama (jumlah pelanggan, tagihan, pembayaran, saldo, paket aktif)
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/dashboard.php`
  - Response: `{ "jumlah_pelanggan": 0, ... }`

### Grafik Transaksi
- **GET /grafik_transaksi.php?tahun=2026** — Grafik transaksi bulanan
  - Contoh: `curl 'https://quenbytekniksejahtera.com/crm/billing/api/grafik_transaksi.php?tahun=2026'`
  - Response: `[ { "bulan": "01", "jumlah_transaksi": 10, "harga": 100000 }, ... ]`

### Log
- **GET /log.php** — Log aktivitas
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/log.php`
  - Response: `{ "data": [ { ... } ] }`

### Topup
- **GET /topup.php** — Data topup
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/topup.php`
  - Response: `{ "data": [ { ... } ] }`

### Panduan
- **GET /panduan.php** — Data panduan
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/panduan.php`
  - Response: `{ "data": [ { ... } ] }`

---

## 4. Endpoint Infrastruktur

### ODP
- **GET /odp.php** — Data ODP
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/odp.php`
  - Response: `{ "data": [ { ... } ] }`

### OLT
- **GET /olt.php** — Data OLT
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/olt.php`
  - Response: `{ "data": [ { ... } ] }`

### VPN
- **GET /vpn.php** — Data VPN
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/vpn.php`
  - Response: `{ "data": [ { ... } ] }`

### IP Pool
- **GET /pool.php** — Data IP Pool
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/pool.php`
  - Response: `{ "data": [ { ... } ] }`

### NMS
- **GET /nms.php** — Data Network Management System
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/nms.php`
  - Response: `{ "data": [ { ... } ] }`

---

## 5. Endpoint Monitoring & Tiket

### Monitoring Tiket
- **GET /monitoring.php** — Data monitoring tiket
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/monitoring.php`
  - Response: `{ "data": [ { ... } ] }`

### Tiket Manager (`/tiket_manager.php`)

CRUD penuh untuk tiket Instalasi / Maintenance / Migrasi / Dismantle yang terikat ke server billing
milik akun yang login (akun ASSISTANT hanya melihat server yang di-assign ke dirinya). Semua kolom
tabel `billing_tiket_manager` dikembalikan apa adanya.

**Dual backend (otomatis, seperti cron)**: sebagian akun disetel untuk menyimpan tiket di tabel
`joblist` (dipakai app teknisi mobile) bukan `billing_tiket_manager` — flag `ticket_management_source`
pada tabel `user`, sama seperti yang dibaca `cek-sesi.php` dan `cron_maintenance_ticket.php` /
`cron_dismantle_ticket.php`. Endpoint ini otomatis mendeteksi flag itu per request dan mengarahkan
CRUD ke tabel yang benar — tidak ada parameter tambahan yang perlu dikirim. Setiap baris tiket
punya field `source` (`"tiket_manager"` atau `"joblist"`) supaya jelas asal datanya. Saat sumbernya
`joblist`, kolom khas billing (`server_id`, `brand`, `area`, `project_name`, `teknisi_user_id`,
`created_by_user_id`, `done_at`) bernilai `null` dan digantikan field asli joblist (`project`, `tim`,
`nowa`, `keterangan`, `tgl`, `waktu`). Detail lengkap: lihat `DOKUMENTASI_TIKET_MANAGER_API.txt`
bagian 1b.

**Autentikasi**: gunakan session login (`login.php`) ATAU sertakan `username` & `password` di setiap
request (query string untuk GET, body JSON untuk POST/PUT/DELETE) — sama seperti `/server.php`.

**Kolom tiket (mode `tiket_manager`)**: `id, judul, detail, server_id, pemilik, brand, area,
project_name, tipe, report, status, teknisi_user_id, created_by_user_id, done_at, created_at,
updated_at`

**Nilai valid**
- `tipe`: `INSTALLASI`, `MAINTENANCE`, `MIGRASI`, `DISMANTLE`
- `status`: `BARU`, `PENDING`, `DONE`, `CANCEL`

#### GET — ambil tiket (status/tipe apa pun, sesuai server milik akun)
Query params opsional: `id`, `status` (default `ALL`), `tipe` (default `ALL`), `server_id`,
`teknisi_user_id`, `brand`, `area`, `project_name`, `q` (cari di judul/detail/report),
`date_from`, `date_to` (format `YYYY-MM-DD`, filter `created_at`), `limit` (maks 500, default 200), `offset`.
```
curl 'https://quenbytekniksejahtera.com/crm/billing/api/tiket_manager.php?username=FIBERQ&password=xxx&status=ALL&tipe=MAINTENANCE'
```
Response: `{ "success": true, "data": [ {...} ], "total": 12, "limit": 200, "offset": 0 }`

#### POST — buat tiket baru
Body JSON wajib: `server_id`, `judul`. Opsional: `detail`, `tipe` (default `INSTALLASI`), `report`,
`status` (default `BARU`), `teknisi_user_id`.
```
curl -X POST -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"xxx","server_id":1,"judul":"Instalasi baru","tipe":"INSTALLASI"}' \
  https://quenbytekniksejahtera.com/crm/billing/api/tiket_manager.php
```

#### PUT — update tiket (kolom apa pun, hanya field yang dikirim yang diubah)
Body JSON wajib: `id`. Opsional: `judul`, `detail`, `report`, `tipe`, `status`, `teknisi_user_id`, `server_id`.
```
curl -X PUT -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"xxx","id":10,"status":"DONE","report":"Selesai dipasang"}' \
  https://quenbytekniksejahtera.com/crm/billing/api/tiket_manager.php
```

#### DELETE — hapus tiket
Body JSON wajib: `id`.
```
curl -X DELETE -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"xxx","id":10}' \
  https://quenbytekniksejahtera.com/crm/billing/api/tiket_manager.php
```

### Livechat
- **GET /livechat.php** — Data livechat
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/livechat.php`
  - Response: `{ "data": [ { ... } ] }`

---

## 6. Endpoint Notifikasi & Upload

### Notifikasi
- **GET /notifikasi.php** — Data notifikasi
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/notifikasi.php`
  - Response: `{ "data": [ { ... } ] }`

### Upload
- **GET /upload.php** — Data upload
  - Contoh: `curl https://quenbytekniksejahtera.com/crm/billing/api/upload.php`
  - Response: `{ "data": [ { ... } ] }`

---

## 7. Endpoint Proxy & Proses Otomatis

### getdata.php
- **GET /getdata.php?file=<file>&param=...** — Proxy data dinamis dari folder getdata
  - Contoh: `curl 'https://quenbytekniksejahtera.com/crm/billing/api/getdata.php?file=get_chart_data.php&bulan=4&tahun=2026'`
  - Response: JSON sesuai file getdata

### proses.php
- **GET /proses.php?file=<file>&param=...** — Proxy proses otomatis dari folder proses
  - Contoh: `curl 'https://quenbytekniksejahtera.com/crm/billing/api/proses.php?file=addserver.php&nama=SERVER1&ip=192.168.1.1'`
  - Response: JSON sesuai file proses

### mikrotik.php
- **GET /mikrotik.php?action=<action>&param=...** — Proxy proses ke Mikrotik
  - Contoh: `curl 'https://quenbytekniksejahtera.com/crm/billing/api/mikrotik.php?action=reboot&id=123'`
  - Response: JSON sesuai proses Mikrotik

---

## 8. Penjelasan Autentikasi & Session

- Login via `/login.php` akan mengembalikan session/cookie.
- Simpan cookie (misal: `-c cookie.txt` di curl) dan gunakan untuk request berikutnya (`-b cookie.txt`).
- Beberapa endpoint (proses, getdata, dsb) membutuhkan session/cookie valid.

---

## 9. Penanganan Error & Response

- Semua response sukses berupa JSON.
- Jika error, response akan mengandung key `error`.
- Untuk proses/getdata, jika output bukan JSON, akan dibungkus dalam `{ "result": "..." }`.
- Error umum: `Method not allowed`, `File not allowed`, `Action not allowed`, `Username tidak ditemukan`, `Password salah`, dsb.

---

## 10. Tips Keamanan & Best Practice

- Selalu gunakan HTTPS.
- Simpan session/cookie setelah login untuk akses endpoint lain.
- Cek response JSON untuk status/error.
- Gunakan parameter sesuai kebutuhan file proses/getdata.
- Untuk integrasi Mikrotik/ACS, gunakan endpoint proxy yang sudah disediakan.
- Jangan share password/credential ke pihak tidak berwenang.
- Gunakan akun dengan hak akses minimum sesuai kebutuhan.

---

## 11. Akun Tes

- Username: `FIBERQ`
- Password: `Deltaiman@92`

---

## 12. Bantuan

Jika butuh bantuan lebih lanjut, silakan hubungi admin/support.


# Dokumentasi Super Lengkap API Billing

Base URL: **https://quenbytekniksejahtera.com/crm/billing/api/**

---

## 1. Autentikasi & Login

### Endpoint
`POST /login.php`

### Request Body (JSON)
```
{
  "username": "FIBERQ",
  "password": "Deltaiman@92"
}
```

### Contoh Curl
```
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"Deltaiman@92"}' \
  https://quenbytekniksejahtera.com/crm/billing/api/login.php
```

### Response Sukses
```
{
  "success": true,
  "user": { ... },
  "akses_menu_login": [ ... ],
  "landing_page": "dashboard.php",
  "session": { ... }
}
```

### Catatan
- Gunakan endpoint ini untuk login dan mendapatkan session/cookie.
- Wajib login sebelum akses endpoint yang membutuhkan autentikasi.

---

## 2. Daftar Endpoint API Utama

| Endpoint | Method | Fungsi | Parameter | Contoh |
|----------|--------|--------|-----------|--------|
| `/dashboard.php` | GET | Statistik utama (jumlah pelanggan, tagihan, pembayaran, saldo, paket aktif) | - | `/dashboard.php` |
| `/pelanggan.php` | GET | List semua pelanggan | - | `/pelanggan.php` |
| `/tagihan.php` | GET | List semua tagihan | - | `/tagihan.php` |
| `/transaksi.php` | GET | List semua transaksi | - | `/transaksi.php` |
| `/paket.php` | GET | List semua paket layanan | - | `/paket.php` |
| `/server.php` | GET | List semua server | - | `/server.php` |
| `/server_area.php` | GET/POST/PUT/DELETE | CRUD area server | id, PEMILIK, AREA | `/server_area.php?id=1` |
| `/getdata.php` | GET | Proxy data dinamis (lihat daftar file di bawah) | file, param | `/getdata.php?file=get_chart_data.php` |
| `/proses.php` | GET | Proxy proses otomatis (lihat daftar file di bawah) | file, param | `/proses.php?file=addserver.php` |
| `/mikrotik.php` | GET | Proxy proses ke Mikrotik | action, param | `/mikrotik.php?action=reboot&id=123` |
| `/login.php` | POST | Login user | username, password | `/login.php` |

---

## 3. Penjelasan Endpoint CRUD

### Contoh: CRUD Server Area (`/server_area.php`)

- **GET**: Ambil semua data area server
  - `curl https://quenbytekniksejahtera.com/crm/billing/api/server_area.php`
- **POST**: Tambah area server
  - Body JSON: `{ "PEMILIK": "NAMA", "AREA": "AREA" }`
  - `curl -X POST -H "Content-Type: application/json" -d '{"PEMILIK":"NAMA","AREA":"AREA"}' https://quenbytekniksejahtera.com/crm/billing/api/server_area.php`
- **PUT/PATCH**: Edit area server
  - Body: `id`, `PEMILIK`, `AREA`
  - `curl -X PUT -d 'id=1&PEMILIK=NAMA&AREA=AREA' https://quenbytekniksejahtera.com/crm/billing/api/server_area.php`
- **DELETE**: Hapus area server
  - Body: `id`
  - `curl -X DELETE -d 'id=1' https://quenbytekniksejahtera.com/crm/billing/api/server_area.php`

---

## 4. Proxy Endpoint: getdata.php & proses.php

### getdata.php
- Untuk mengambil data dinamis dari folder `/getdata/`.
- Hanya file yang diizinkan (lihat variabel `$allowed` di getdata.php).
- Contoh:
  - `curl 'https://quenbytekniksejahtera.com/crm/billing/api/getdata.php?file=get_chart_data.php&bulan=4&tahun=2026'`

### proses.php
- Untuk menjalankan proses otomatis dari folder `/proses/`.
- Hanya file yang diizinkan (lihat variabel `$allowed` di proses.php).
- Contoh:
  - `curl 'https://quenbytekniksejahtera.com/crm/billing/api/proses.php?file=addserver.php&nama=SERVER1&ip=192.168.1.1'`

### mikrotik.php
- Untuk menjalankan proses ke Mikrotik (reboot, update, cek, dsb).
- Contoh:
  - `curl 'https://quenbytekniksejahtera.com/crm/billing/api/mikrotik.php?action=reboot&id=123'`

---

## 5. Daftar File getdata.php & proses.php yang Diizinkan

### getdata.php (contoh file):
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

### proses.php (contoh file):
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

---

## 6. Contoh Skenario Penggunaan

### a. Login dan Ambil Data Pelanggan
```
# Login
curl -c cookie.txt -X POST -H "Content-Type: application/json" -d '{"username":"FIBERQ","password":"Deltaiman@92"}' https://quenbytekniksejahtera.com/crm/billing/api/login.php

# Ambil data pelanggan (pakai cookie session)
curl -b cookie.txt https://quenbytekniksejahtera.com/crm/billing/api/pelanggan.php
```

### b. Tambah Server Area
```
curl -X POST -H "Content-Type: application/json" -d '{"PEMILIK":"NAMA","AREA":"AREA"}' https://quenbytekniksejahtera.com/crm/billing/api/server_area.php
```

### c. Proses Otomatis (misal: tambah server)
```
curl 'https://quenbytekniksejahtera.com/crm/billing/api/proses.php?file=addserver.php&nama=SERVER1&ip=192.168.1.1'
```

### d. Monitoring Tiket
```
curl https://quenbytekniksejahtera.com/crm/billing/api/monitoring.php
```

### e. Proses ke Mikrotik (misal: reboot)
```
curl 'https://quenbytekniksejahtera.com/crm/billing/api/mikrotik.php?action=reboot&id=123'
```

---

## 7. Response & Error Handling

- Semua response sukses akan berupa JSON.
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

## 4. Contoh Penggunaan dengan Tes Akun
### Login
```
curl -X POST \
  -H "Content-Type: application/json" \
  -d '{"username":"FIBERQ","password":"Deltaiman@92"}' \
  https://quenbytekniksejahtera.com/crm/billing/api/login.php
```
### Menambah Server (addserver.php)
```
curl -G \
  --data-urlencode "file=addserver.php" \
  --data-urlencode "nama=SERVER1" \
  --data-urlencode "ip=192.168.1.1" \
  https://quenbytekniksejahtera.com/crm/billing/api/proses.php
```

## 5. Tips
- Gunakan HTTPS untuk keamanan.
- Pastikan parameter sesuai kebutuhan file proses.
- Cek response JSON untuk status dan pesan error.

---

**Akun Tes:**
- Username: `FIBERQ`
- Password: `Deltaiman@92`

