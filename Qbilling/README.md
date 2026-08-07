# Qbilling Android Project

Aplikasi Android untuk mengakses API Billing (https://quenbytekniksejahtera.com/crm/billing/api/)

## Fitur Utama
- Login user
- Lihat data pelanggan, tagihan, transaksi, paket, server, ODP, OLT, VPN, pool, monitoring, tiket, livechat, notifikasi, upload, log, topup, panduan, dan lainnya
- CRUD data (jika diizinkan API)
- Proses otomatis (proses.php, getdata.php, mikrotik.php)
- Notifikasi dan monitoring

## Integrasi API
- Semua komunikasi menggunakan endpoint API yang sudah terdokumentasi di folder /api
- Gunakan autentikasi login (POST /login.php), simpan session/cookie
- Semua data diambil dan dikirim dalam format JSON

## Struktur Awal
- MainActivity.java / MainActivity.kt
- LoginActivity.java / LoginActivity.kt
- ApiService.java / ApiService.kt
- model/ (data class)
- utils/ (helper, session, dsb)

## Contoh Request Login (Kotlin/Java)
```kotlin
val url = "https://quenbytekniksejahtera.com/crm/billing/api/login.php"
val json = JSONObject()
json.put("username", "FIBERQ")
json.put("password", "Deltaiman@92")
// Kirim POST dengan OkHttp/Retrofit/Volley
```

## Catatan
- Semua endpoint, parameter, dan response sudah dijelaskan di api/README.md
- Silakan lanjutkan pengembangan aplikasi Android sesuai kebutuhan UI/UX
