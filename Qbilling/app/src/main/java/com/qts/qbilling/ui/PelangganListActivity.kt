package com.qts.qbilling.ui

import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.qts.qbilling.ApiServiceExt
import kotlinx.coroutines.launch

class PelangganListActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        lifecycleScope.launch {
            val pelangganList = ApiServiceExt.getPelanggan()
            val adapter = ArrayAdapter(
                this@PelangganListActivity,
                android.R.layout.simple_list_item_1,
                pelangganList.map { it.nama ?: "(Tanpa Nama)" }
            )
            listView.adapter = adapter
        }
    }
}
