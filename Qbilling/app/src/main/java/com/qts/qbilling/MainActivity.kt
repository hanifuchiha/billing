package com.qts.qbilling

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import android.widget.Toast
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        val prefs = getSharedPreferences("qbilling_prefs", MODE_PRIVATE)
        val username = prefs.getString("username", null)
        val nextActivity = if (username.isNullOrEmpty()) {
            LoginActivity::class.java
        } else {
            com.qts.qbilling.ui.HomeActivity::class.java
        }
        val intent = android.content.Intent(this, nextActivity)
        startActivity(intent)
        finish()
    }
}
