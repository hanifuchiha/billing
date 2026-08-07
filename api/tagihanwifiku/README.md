# Tagihan Wifiku API

API mobile khusus aplikasi Android Tagihan Wifiku.

## Endpoint

- `POST /crm/billing/api/tagihanwifiku/login_otp_request.php`
- `POST /crm/billing/api/tagihanwifiku/login_otp_verify.php`
- `POST /crm/billing/api/tagihanwifiku/login_password.php`
- `GET /crm/billing/api/tagihanwifiku/me.php`
- `GET /crm/billing/api/tagihanwifiku/billing.php`
- `GET /crm/billing/api/tagihanwifiku/payment_methods.php`
- `GET /crm/billing/api/tagihanwifiku/payment_status.php`
- `POST /crm/billing/api/tagihanwifiku/payment_create.php`
- `POST /crm/billing/api/tagihanwifiku/payment_cancel.php`
- `GET /crm/billing/api/tagihanwifiku/history.php?limit=30`
- `GET /crm/billing/api/tagihanwifiku/chat_messages.php?limit=80`
- `POST /crm/billing/api/tagihanwifiku/chat_send.php`
- `GET /crm/billing/api/tagihanwifiku/wifi_status.php`
- `POST /crm/billing/api/tagihanwifiku/wifi_save.php`
- `GET /crm/billing/api/tagihanwifiku/complaint_history.php?limit=30`
- `POST /crm/billing/api/tagihanwifiku/complaint_create.php`
- `POST /crm/billing/api/tagihanwifiku/profile_update.php`
- `POST /crm/billing/api/tagihanwifiku/logout.php`

## Header Auth

Gunakan bearer token untuk endpoint terproteksi:

`Authorization: Bearer <token>`

## Catatan implementasi

- Data pelanggan diambil dari tabel `pelanggan`.
- Data tagihan diambil dari tabel `invoice`.
- Data riwayat transaksi diambil dari tabel `transaksi`.
- Sesi mobile disimpan di tabel `twk_mobile_sessions`.
- OTP disimpan di tabel `twk_otp_codes`.
- Tabel sesi/OTP akan otomatis dibuat jika belum ada.
