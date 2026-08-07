package com.qts.qbilling.ui

import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray

class GenericListActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        val title = intent.getStringExtra("title") ?: "Data"
        title?.let { setTitle(it) }
        val loaderKey = intent.getStringExtra("loaderKey") ?: ""
        lifecycleScope.launch {
            val items = withContext(Dispatchers.IO) {
                try {
                    when (loaderKey) {
                        "Dashboard" -> {
                            val obj = com.qts.qbilling.ApiServiceAll.getRaw("dashboard.php")
                            val labels = mapOf(
                                "jumlah_pelanggan" to "Jumlah Pelanggan",
                                "jumlah_tagihan" to "Jumlah Tagihan",
                                "jumlah_pembayaran" to "Jumlah Pembayaran",
                                "total_saldo" to "Total Saldo",
                                "jumlah_paket_aktif" to "Jumlah Paket Aktif"
                            )
                            labels.map { (key, label) -> "$label: ${obj.opt(key)}" }
                        }
                        else -> {
                            val obj = com.qts.qbilling.ApiServiceAll.getRaw(
                                when (loaderKey) {
                                    "Pelanggan" -> "pelanggan.php"
                                    "Tagihan" -> "tagihan.php"
                                    "Transaksi" -> "transaksi.php"
                                    "Paket" -> "paket.php"
                                    "Server" -> "server.php"
                                    "ODP" -> "odp.php"
                                    "OLT" -> "olt.php"
                                    "VPN" -> "vpn.php"
                                    "IP Pool" -> "pool.php"
                                    "NMS" -> "nms.php"
                                    "Monitoring Tiket" -> "monitoring.php"
                                    "Tiket" -> "tiket_manager.php"
                                    "Livechat" -> "livechat.php"
                                    "Notifikasi" -> "notifikasi.php"
                                    "Upload" -> "upload.php"
                                    "Log" -> "log.php"
                                    "Topup" -> "topup.php"
                                    "Panduan" -> "panduan.php"
                                    else -> ""
                                }
                            )
                            val arr = obj.optJSONArray("data") ?: JSONArray()
                            (0 until arr.length()).map { i ->
                                val o = arr.optJSONObject(i)
                                o?.toString() ?: arr.optString(i)
                            }
                        }
                    }
                } catch (e: Exception) {
                    listOf("Error: ${e.localizedMessage}")
                }
            }
            val adapter = ArrayAdapter(this@GenericListActivity, android.R.layout.simple_list_item_1, items)
            listView.adapter = adapter
        }
    }
}
