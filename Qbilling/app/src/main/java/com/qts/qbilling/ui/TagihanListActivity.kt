package com.qts.qbilling.ui

import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.qts.qbilling.ApiServiceExt
import kotlinx.coroutines.launch

class TagihanListActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        lifecycleScope.launch {
            val tagihanList = ApiServiceExt.getTagihan()
            val adapter = ArrayAdapter(
                this@TagihanListActivity,
                android.R.layout.simple_list_item_1,
                tagihanList.map { "Tagihan: ${it.nominal} - Status: ${it.status}" }
            )
            listView.adapter = adapter
        }
    }
}
