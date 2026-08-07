package com.qts.qbilling.ui

import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.qts.qbilling.ApiServiceExt
import kotlinx.coroutines.launch

class TransaksiListActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        lifecycleScope.launch {
            val transaksiList = ApiServiceExt.getTransaksi()
            val adapter = ArrayAdapter(
                this@TransaksiListActivity,
                android.R.layout.simple_list_item_1,
                transaksiList.map { "Transaksi: ${it.jumlah} - Status: ${it.status}" }
            )
            listView.adapter = adapter
        }
    }
}
