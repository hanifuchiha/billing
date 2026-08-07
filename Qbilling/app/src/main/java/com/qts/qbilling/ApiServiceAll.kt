package com.qts.qbilling

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object ApiServiceAll {
        // Generic GET data (tanpa key)
        suspend fun getRaw(endpoint: String): org.json.JSONObject = withContext(Dispatchers.IO) {
            val url = URL(BASE_URL + endpoint)
            val conn = url.openConnection() as HttpURLConnection
            val response = conn.inputStream.bufferedReader().readText()
            org.json.JSONObject(response)
        }

        // Generic POST/PUT/DELETE (CRUD)
        suspend fun sendData(endpoint: String, method: String = "POST", body: org.json.JSONObject? = null): org.json.JSONObject = withContext(Dispatchers.IO) {
            val url = URL(BASE_URL + endpoint)
            val conn = url.openConnection() as HttpURLConnection
            conn.requestMethod = method
            conn.setRequestProperty("Content-Type", "application/json")
            if (method == "POST" || method == "PUT") {
                conn.doOutput = true
                conn.outputStream.use { it.write(body?.toString()?.toByteArray() ?: ByteArray(0)) }
            }
            val response = conn.inputStream.bufferedReader().readText()
            org.json.JSONObject(response)
        }

        // Proxy proses.php
        suspend fun proses(file: String, params: Map<String, String>): org.json.JSONObject = withContext(Dispatchers.IO) {
            val paramStr = params.entries.joinToString("&") { "${it.key}=${it.value}" }
            val url = URL(BASE_URL + "proses.php?file=$file&$paramStr")
            val conn = url.openConnection() as HttpURLConnection
            val response = conn.inputStream.bufferedReader().readText()
            org.json.JSONObject(response)
        }

        // Proxy getdata.php
        suspend fun getData(file: String, params: Map<String, String>): org.json.JSONObject = withContext(Dispatchers.IO) {
            val paramStr = params.entries.joinToString("&") { "${it.key}=${it.value}" }
            val url = URL(BASE_URL + "getdata.php?file=$file&$paramStr")
            val conn = url.openConnection() as HttpURLConnection
            val response = conn.inputStream.bufferedReader().readText()
            org.json.JSONObject(response)
        }

        // Proxy mikrotik.php
        suspend fun mikrotik(action: String, params: Map<String, String>): org.json.JSONObject = withContext(Dispatchers.IO) {
            val paramStr = params.entries.joinToString("&") { "${it.key}=${it.value}" }
            val url = URL(BASE_URL + "mikrotik.php?action=$action&$paramStr")
            val conn = url.openConnection() as HttpURLConnection
            val response = conn.inputStream.bufferedReader().readText()
            org.json.JSONObject(response)
        }
    private const val BASE_URL = "https://quenbytekniksejahtera.com/crm/billing/api/"

    suspend fun getServer(): JSONArray = getArray("server.php", "data")
    suspend fun getOdp(): JSONArray = getArray("odp.php", "data")
    suspend fun getOlt(): JSONArray = getArray("olt.php", "data")
    suspend fun getVpn(): JSONArray = getArray("vpn.php", "data")
    suspend fun getPool(): JSONArray = getArray("pool.php", "data")
    suspend fun getNms(): JSONArray = getArray("nms.php", "data")
    suspend fun getMonitoring(): JSONArray = getArray("monitoring.php", "data")
    suspend fun getTiket(): JSONArray = getArray("tiket_manager.php", "data")
    suspend fun getLivechat(): JSONArray = getArray("livechat.php", "data")
    suspend fun getNotifikasi(): JSONArray = getArray("notifikasi.php", "data")
    suspend fun getUpload(): JSONArray = getArray("upload.php", "data")
    suspend fun getLog(): JSONArray = getArray("log.php", "data")
    suspend fun getTopup(): JSONArray = getArray("topup.php", "data")
    suspend fun getPanduan(): JSONArray = getArray("panduan.php", "data")
    suspend fun getDashboard(): JSONArray = getArray("dashboard.php", "data")
    suspend fun getPelanggan(): JSONArray = getArray("pelanggan.php", "data")
    suspend fun getTagihan(): JSONArray = getArray("tagihan.php", "data")
    suspend fun getTransaksi(): JSONArray = getArray("transaksi.php", "data")
    suspend fun getPaket(): JSONArray = getArray("paket.php", "data")
    suspend fun getGrafikTransaksi(tahun: String): JSONArray = getArray("grafik_transaksi.php?tahun=$tahun", null)
    // Tambahkan endpoint lain sesuai kebutuhan

    private suspend fun getArray(endpoint: String, key: String?): JSONArray = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + endpoint)
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        if (key == null) JSONArray(response)
        else JSONObject(response).optJSONArray(key) ?: JSONArray()
    }
    private suspend fun getObject(endpoint: String): JSONObject = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + endpoint)
        val conn = url.openConnection() as HttpURLConnection
        val response = conn.inputStream.bufferedReader().readText()
        JSONObject(response)
    }
}
