package com.qts.qbilling

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object ApiServiceExt {
    private const val BASE_URL = "https://quenbytekniksejahtera.com/crm/billing/api/"

    suspend fun getPelanggan(): List<Pelanggan> = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + "pelanggan.php")
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        val data = JSONObject(response).optJSONArray("data") ?: JSONArray()
        List(data.length()) { i ->
            val o = data.getJSONObject(i)
            Pelanggan(
                id = o.optString("id"),
                nama = o.optString("nama"),
                alamat = o.optString("alamat"),
                no_hp = o.optString("no_hp"),
                status = o.optString("status")
            )
        }
    }

    suspend fun getTagihan(): List<Tagihan> = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + "tagihan.php")
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        val data = JSONObject(response).optJSONArray("data") ?: JSONArray()
        List(data.length()) { i ->
            val o = data.getJSONObject(i)
            Tagihan(
                id = o.optString("id"),
                pelanggan_id = o.optString("pelanggan_id"),
                nominal = o.optString("nominal"),
                status = o.optString("status"),
                jatuh_tempo = o.optString("jatuh_tempo")
            )
        }
    }

    suspend fun getTransaksi(): List<Transaksi> = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + "transaksi.php")
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        val data = JSONObject(response).optJSONArray("data") ?: JSONArray()
        List(data.length()) { i ->
            val o = data.getJSONObject(i)
            Transaksi(
                id = o.optString("id"),
                pelanggan_id = o.optString("pelanggan_id"),
                jumlah = o.optString("jumlah"),
                tanggal = o.optString("tanggal"),
                status = o.optString("status")
            )
        }
    }

    suspend fun getPaket(): List<Paket> = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + "paket.php")
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        val data = JSONObject(response).optJSONArray("data") ?: JSONArray()
        List(data.length()) { i ->
            val o = data.getJSONObject(i)
            Paket(
                id = o.optString("id"),
                nama = o.optString("nama"),
                harga = o.optString("harga"),
                status = o.optString("status")
            )
        }
    }
}
