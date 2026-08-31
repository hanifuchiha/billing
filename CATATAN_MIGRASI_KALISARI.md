# Catatan Migrasi Site Kalisari

## Kondisi saat ini

- Site Keuangan: `Air-13790_KALISARI`
- Site ID Keuangan: `23`
- AREA Billing: `13790.01-12_signal_kali sari`
- Pelanggan Keuangan: 102
- Riwayat pembayaran: 190
- Pembayaran gagal sinkron: 48 (`HTTP 301`)
- Pembayaran lama berstatus `not_required`: 142
- Pelanggan pada AREA Billing Kalisari masih kosong.

## Keputusan

Migrasi pelanggan dan transaksi diprioritaskan menggunakan API, bukan impor Excel.

## Alur migrasi API

1. Buat mapping AREA Billing `13790.01-12_signal_kali sari` ke Site Keuangan `Air-13790_KALISARI` ID `23`.
2. Perbaiki endpoint Keuangan ke Billing yang masih menghasilkan `HTTP 301`.
3. Aktifkan status `PAUSE ISOLIR` hanya untuk AREA Kalisari. AREA lain tetap diproses normal.
4. Billing mengambil pelanggan melalui API dengan filter `site_ref_id=23`.
5. Validasi `IDPEL`, AREA, paket, profil MikroTik/RADIUS, tipe pembayaran, dan tanggal pemasangan.
6. Masukkan pelanggan yang belum tersedia di Billing tanpa membuat data ganda.
7. Ambil transaksi pembayaran Site ID `23` melalui API.
8. Masukkan transaksi pemasukan sebagai `BERHASIL` dengan ID transaksi Keuangan sebagai kunci unik/idempoten.
9. Prioritaskan pembayaran periode aktif; histori lama dapat di-backfill bertahap.
10. Verifikasi jumlah pelanggan, transaksi periode aktif, status sinkron, dan profil pelanggan.
11. Lepaskan `PAUSE ISOLIR` setelah validasi berhasil agar isolir AREA Kalisari kembali berjalan otomatis.

## Fitur yang perlu ditambahkan

- Filter API khusus `site_ref_id=23`.
- Kunci unik transaksi berdasarkan ID pembayaran/transaksi asal Keuangan agar proses ulang tidak membuat duplikasi.
- Status `PAUSE/RESUME ISOLIR` per AREA/Site.
- Otomatisasi migrasi melalui tombol **Migrasikan Site dari Keuangan**.
- Validasi akhir sebelum isolir diaktifkan kembali.
- Log hasil migrasi: berhasil, dilewati, gagal, dan alasan kegagalan.

## Aturan pengaman

- Jangan membuka isolir sebelum pembayaran periode aktif selesai dicocokkan.
- Pelanggan dicocokkan berdasarkan `IDPEL`, bukan nama.
- Jika `IDPEL` tidak ditemukan atau ganda, transaksi ditahan untuk pemeriksaan.
- Proses API harus aman dijalankan ulang tanpa membuat pelanggan atau transaksi ganda.
- Pelanggan yang sudah membayar dan masih berprofil `EXPIRED` harus dipulihkan otomatis ke profil paket normal.

## Ketentuan isolir tanggal 1 September

- Pelanggan Kalisari yang diimpor pada 1 September dengan `TIPE_TEMPO = mengikuti_tanggal_tempo` tidak terkena isolir otomatis pada tanggal tersebut.
- Konfigurasi Airlink saat ini menggunakan tanggal 1 untuk reminder dan tanggal 10 sebagai tanggal pemeriksaan isolir.
- Pelanggan baru harus memakai profil MikroTik/RADIUS normal, bukan `EXPIRED`.
- Pembayaran periode aktif harus selesai disinkronkan sebelum tanggal 10.
- Pengecualian: pelanggan dengan `TIPE_TEMPO = tanggal_tetap_personal` dapat diisolir tanggal 1 jika tanggal pemasangannya juga tanggal 1.
