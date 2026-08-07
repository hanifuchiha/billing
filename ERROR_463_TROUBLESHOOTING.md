# Troubleshooting Error 463 - WhatsApp Bot

## Apa itu Error 463?

Error 463 **bukan standard HTTP code**. Ini adalah custom error yang bisa berarti:
- Session/Auth gagal
- Format data tidak valid
- Bot server sedang bermasalah
- Rate limiting/overload

---

## 🔍 Debugging Steps

### 1. Cek Log Debug
```bash
# Check file debug log
tail -100 crm/billing/tester_debug.log
```

File ini sekarang berisi:
- HTTP status code
- CURL error (jika ada)
- Payload yang dikirim
- Full response dari bot

---

## 2. Validasi Data Input

### Format Nomor Telepon
```php
// ✅ BENAR - gunakan format ini
$testerPhone = '628123456789';  // Format: 62 + nomor tanpa leading 0

// ❌ SALAH - jangan gunakan format ini
$testerPhone = '08123456789';   // Format Indonesia standard
$testerPhone = '+62123456789';  // Format dengan +
```

### Regex Validation di wabot.php (baris 96):
```
preg_match('/^62\d{8,15}$/', $testerPhone)
```
Artinya:
- Harus dimulai dengan `62` (Indonesia country code)
- Diikuti 8-15 digit
- Total: 10-17 digit

---

## 3. Cek Bot Server Connection

### Pastikan Bot Running
```bash
# Test koneksi ke bot server
curl -v http://ADDRESSBOT_IP:PORT/

# Contoh
curl -v http://192.168.1.100:3000/
```

### Verifikasi di Database
```sql
-- Check bot configuration
SELECT id, namebot, addressbot, password, sender 
FROM botwa 
WHERE id = YOUR_BOT_ID;
```

**Pastikan:**
- ✅ `addressbot` accessible (bukan localhost jika akses dari lain server)
- ✅ `namebot` dan `password` valid
- ✅ Port tidak blocked

---

## 4. Test Manual dengan cURL

```bash
# Test send message ke bot server
curl -X POST "http://addressbot:port/send/message?session=NAMEBOT" \
  -H "Content-Type: application/json" \
  -u "namebot:password" \
  -d '{
    "phone": "628123456789@s.whatsapp.net",
    "message": "Test message",
    "sender": "SENDER_NAME"
  }'
```

---

## 5. Format Phone Number di go-whatsapp-web-multidevice

Dari dokumentasi, phone bisa dalam format:
- `628123456789@s.whatsapp.net` (dengan JID suffix)
- `628123456789` (tanpa suffix, API auto-convert)

**Di wabot.php Anda:**
- Line 118-119: Input `$testerPhone` di-remove non-digit: `preg_replace('/[^0-9]/', '', ...)`
- Tapi di line 121-125 payload: tidak ditambah `@s.whatsapp.net`

### ⚠️ Mungkin ini masalahnya!

---

## 6. Solusi: Update Payload Format

### Option A: Cek dokumentasi API bot Anda
Lihat `whatsapp_helper.php` atau documentation bot:
```php
// Mungkin butuh format:
'phone' => '628123456789@s.whatsapp.net', // Full JID
// atau
'phone' => '628123456789', // Hanya nomor
```

### Option B: Tambah error handling & retry logic

```php
if ($httpCode === 463) {
    // Retry dengan format berbeda
    $payloadRetry = $payload;
    $payloadRetry['phone'] = '628123456789@s.whatsapp.net'; // Coba dengan JID
    
    // Retry curl request...
}
```

---

## 7. Check Bot Server Logs

Jika punya akses ke server bot:
```bash
# Go WhatsApp Web logs
docker logs whatsapp    # Jika pakai Docker
tail -100 /var/log/whatsapp/... # Jika direct binary
journalctl -u whatsapp -n 50   # Jika systemd service
```

---

## 8. Common Error 463 Solutions

| Problem | Solution |
|---------|----------|
| Auth invalid | Update `namebot` & `password` di database |
| Format phone salah | Gunakan format `62...` tanpa leading 0 |
| Bot offline | Start bot service, cek port |
| Rate limit | Tunggu beberapa detik, implement retry logic |
| Session expired | Login ulang ke bot, refresh session |
| Network issue | Cek firewall, ensure bot addressable |

---

## 9. Debug Mode yang Sudah Ditambah

File debug log sekarang mencatat:
- Timestamp
- URL yang dipanggil
- HTTP status code
- CURL error (jika ada)
- Payload JSON
- Full response
- Auth string (password di-mask)

**Lokasi:** `crm/billing/tester_debug.log`

---

## 10. Rekomendasi Implementasi

### Tambah Validation Script
```php
// crm/billing/validate_bot_config.php
function validateBotConfig($botId) {
    $stmt = $conn->prepare("SELECT * FROM botwa WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $bot = $stmt->get_result()->fetch_assoc();
    
    if (!$bot) return "Bot tidak ditemukan";
    
    // Test koneksi
    $ch = curl_init($bot['addressbot']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return "Connection error: $error";
    }
    if ($httpCode === 0) {
        return "Bot tidak accessible di: {$bot['addressbot']}";
    }
    
    return "✅ Bot config valid";
}
```

---

## 11. Next Steps

1. **Check log:** Lihat `tester_debug.log` untuk detail error
2. **Validate phone format:** Pastikan `62...` format
3. **Test koneksi:** Manual curl test ke bot server
4. **Check bot service:** Pastikan bot running & accessible
5. **Verify credentials:** `namebot` dan `password` di database valid
