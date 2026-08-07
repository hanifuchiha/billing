# AJAX Implementation Guide - Notification.php

## Ringkasan
Sistem simpan notifikasi telah diubah menjadi AJAX background processing. Semua form sekarang mengirim request ke API endpoint terpisah tanpa reload halaman.

## Infrastruktur yang Sudah Ada

### 1. API Endpoint
- **File:** `/crm/billing/api/ajax-save-notification.php`
- **Method:** POST
- **Response:** JSON dengan struktur:
```json
{
  "success": true/false,
  "message": "Deskripsi hasil",
  "type": "nama_section"
}
```

### 2. JavaScript Functions
- **showToastNotif(title, message, type)** - Menampilkan notifikasi toast
- **saveViaAjax(action, formData, callback)** - Generic AJAX save function
- **Form event handler** - Menangkap submit event dari form dengan `data-save-type`

## Cara Menggunakan

### Update Form untuk AJAX

Tambahkan attribute `data-save-type` pada setiap `<form>` tag:

```html
<!-- Contoh 1: Pesan Registrasi -->
<form method="post" data-save-type="pesan_registrasi">
    <textarea name="pesan_registrasi" required>...</textarea>
    <button type="submit" class="btn btn-primary">Simpan</button>
</form>

<!-- Contoh 2: Nomor Penerima -->
<form method="post" data-save-type="nomor_penerima">
    <input type="text" name="nomor_penerima" required>
    <select name="tipe_penerima">
        <option value="pribadi">Pribadi</option>
        <option value="grup">Grup</option>
    </select>
    <button type="submit">Simpan</button>
</form>
```

### Available data-save-type Values

#### Pesan Notifikasi
- `pesan_registrasi` - Field: `pesan_registrasi`
- `pesan_expired` - Field: `pesan_expired`
- `pesan_reminder` - Field: `pesan_reminder`
- `pesan_ketentuan` - Field: `pesan_ketentuan`
- `pesan_disable` - Field: `pesan_disable`
- `pesan_aktif_manual` - Field: `pesan_aktif_manual`
- `pesan_remainder_manual` - Field: `pesan_remainder_manual`
- `pesan_dismantle_manual` - Field: `pesan_dismantle_manual`

#### Penerima Pesan
- `nomor_penerima` - Fields: `nomor_penerima`, `tipe_penerima`, `bot_penerima`
- `penerima_server` - Fields: `nomor_penerima_server`, `tipe_penerima_server`, `bot_penerima_server`
- `penerima_livechat` - Fields: `nomor_penerima_livechat`, `tipe_penerima_livechat`
- `penerima_system_notif` - Fields: `nomor_penerima_system_notif`, `tipe_penerima_system_notif`
- `penerima_odp_los` - Fields: `nomor_penerima_odp_los`, `tipe_penerima_odp_los`
- `penerima_manual_active` - Fields: `nomor_penerima_manual_active`, `tipe_penerima_manual_active`
- `penerima_provisioning` - Fields: `nomor_penerima_provisioning`, `tipe_penerima_provisioning`

#### Jadwal Notifikasi
- `interval_odp_los` - Field: `interval_odp_los`
- `prabayar_grace_period` - Field: `prabayar_grace_period`
- `invoice_generator` - Fields: `invoice_generator_enabled`, `invoice_generator_schedule`

#### Pengaturan Lanjutan
- `otp_template` - Field: `otp_portal_template`
- `dynamic_greeting` - Fields: `dynamic_greeting_enabled`, `dynamic_greeting_list`

## Validasi & Error Handling

### Client-Side (JavaScript)
- Form tidak boleh kosong (required attribute)
- Toast notifikasi menampilkan pesan sukses/error
- Auto-remove toast setelah 4 detik

### Server-Side (PHP API)
- Validasi field wajib ada
- Format nomor WhatsApp:
  - Pribadi: Harus mulai `62`, 7-15 digit setelah `62`, format: `62XXXXXXXXX@s.whatsapp.net`
  - Grup: Format: `XXXX@g.us`
- Pesan tidak boleh kosong
- Interval harus nilai positif

## Response Codes & Messages

| Kondisi | Success | Message |
|---------|---------|---------|
| Pesan disimpan | true | "Pesan [type] berhasil disimpan" |
| Field kosong | false | "[Field name] tidak boleh kosong" |
| Format nomor salah | false | "Nomor pribadi harus diawali 62 dan hanya angka..." |
| Database error | false | "Execute error: [error detail]" |
| Action tidak dikenali | false | "Action tidak dikenali: [action name]" |

## History & Logging

Setiap save otomatis dilogkan ke file history:
- **Lokasi:** `/crm/billing/notifbot/data/history-{username}.json`
- **Format:** JSON array dengan timestamp dan action
- **Contoh:**
```json
[
  "[ Admin Name - 2026-04-16 10:30:15 ] Menyimpan pesan registrasi",
  "[ Admin Name - 2026-04-16 10:35:42 ] Menyimpan nomor penerima: 62812345678@s.whatsapp.net"
]
```

## Keuntungan AJAX Implementation

1. ✅ **Tanpa Reload** - Halaman tetap di posisi yang sama
2. ✅ **User-Friendly** - Toast notification untuk feedback
3. ✅ **Faster** - Tidak perlu tunggu page load ulang
4. ✅ **Logged** - Semua aksi tercatat di history
5. ✅ **Validated** - Validasi ketat server-side
6. ✅ **Separated** - API terpisah dari UI (clean architecture)

## Troubleshooting

### Toast tidak muncul
- Periksa browser console untuk error
- Pastikan jQuery sudah loaded
- Cek response API di Network tab

### Form submit masih reload halaman
- Pastikan form punya `data-save-type` attribute
- Cek apakah ada error di JavaScript console
- Reload halaman setelah update kode

### Error "Action tidak dikenali"
- Cek apakah `data-save-type` ada di form
- Pastikan nilai `data-save-type` sesuai dengan list yang disediakan

### Nomor tidak tersimpan
- Cek format nomor (harus `62` + digits)
- Lihat error message di toast
- Cek database table `botwa` dan field `penerima`

## Integrasi dengan Form Existing

Jika ada form lama yang menggunakan method POST dengan isset(), form masih bisa bekerja tapi akan page-refresh. Untuk AJAX:

1. Tambahkan `data-save-type="xxx"` ke form tag
2. Hapus attribute `name` pada submit button (opsional)
3. Test di browser

Contoh transisi:
```html
<!-- BEFORE (Page Refresh) -->
<form method="post">
    <input type="text" name="pesan_registrasi">
    <button type="submit" name="simpan_pesan_registrasi">Simpan</button>
</form>

<!-- AFTER (AJAX, No Refresh) -->
<form method="post" data-save-type="pesan_registrasi">
    <input type="text" name="pesan_registrasi">
    <button type="submit" class="btn btn-primary">Simpan</button>
</form>
```

## Testing Checklist

- [ ] Form submit tidak reload halaman
- [ ] Toast notifikasi muncul dengan pesan
- [ ] Data tersimpan di database
- [ ] History terupdate dengan action
- [ ] Error handling bekerja (kosong field, format salah, dll)
- [ ] Multiple save dalam satu session berhasil
- [ ] Scroll position tetap setelah save
