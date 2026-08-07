package com.qts.qbilling.ui

import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.qts.qbilling.ApiServiceExt
import kotlinx.coroutines.launch

class PaketListActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        lifecycleScope.launch {
            val paketList = ApiServiceExt.getPaket()
            val adapter = ArrayAdapter(
                this@PaketListActivity,
                android.R.layout.simple_list_item_1,
                paketList.map { "${it.nama} - Rp${it.harga}" }
            )
            listView.adapter = adapter
        }
    }
}
