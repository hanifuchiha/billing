package com.qts.qbilling

data class Pelanggan(
    val id: String?,
    val nama: String?,
    val alamat: String?,
    val no_hp: String?,
    val status: String?
)

data class Tagihan(
    val id: String?,
    val pelanggan_id: String?,
    val nominal: String?,
    val status: String?,
    val jatuh_tempo: String?
)

data class Transaksi(
    val id: String?,
    val pelanggan_id: String?,
    val jumlah: String?,
    val tanggal: String?,
    val status: String?
)

data class Paket(
    val id: String?,
    val nama: String?,
    val harga: String?,
    val status: String?
)
data class Server(
    val id: String?,
    val nama: String?,
    val ip: String?,
    val lokasi: String?,
    val status: String?
)

data class ODP(
    val id: String?,
    val nama: String?,
    val lokasi: String?,
    val kapasitas: String?,
    val status: String?
)

data class OLT(
    val id: String?,
    val nama: String?,
    val ip: String?,
    val lokasi: String?,
    val status: String?
)

data class VPN(
    val id: String?,
    val nama: String?,
    val ip: String?,
    val status: String?
)

data class Pool(
    val id: String?,
    val nama: String?,
    val range: String?,
    val status: String?
)

data class NMS(
    val id: String?,
    val nama: String?,
    val ip: String?,
    val status: String?
)

data class Monitoring(
    val id: String?,
    val tiket_id: String?,
    val status: String?,
    val waktu: String?
)

data class Tiket(
    val id: String?,
    val pelanggan_id: String?,
    val deskripsi: String?,
    val status: String?,
    val waktu: String?
)

data class Livechat(
    val id: String?,
    val pelanggan_id: String?,
    val pesan: String?,
    val waktu: String?,
    val status: String?
)

data class Notifikasi(
    val id: String?,
    val judul: String?,
    val pesan: String?,
    val waktu: String?,
    val status: String?
)

data class Upload(
    val id: String?,
    val nama_file: String?,
    val url: String?,
    val waktu: String?,
    val status: String?
)

data class Log(
    val id: String?,
    val aksi: String?,
    val user: String?,
    val waktu: String?
)

data class Topup(
    val id: String?,
    val pelanggan_id: String?,
    val jumlah: String?,
    val waktu: String?,
    val status: String?
)

data class Panduan(
    val id: String?,
    val judul: String?,
    val isi: String?,
    val status: String?
)

data class Dashboard(
    val total_pelanggan: String?,
    val total_tagihan: String?,
    val total_transaksi: String?,
    val total_paket: String?
)
