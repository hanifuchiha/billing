package com.qts.qbilling

import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)

        val prefs = getSharedPreferences("qbilling_prefs", MODE_PRIVATE)
        val usernameEdit = findViewById<EditText>(R.id.editUsername)
        val passwordEdit = findViewById<EditText>(R.id.editPassword)
        val loginBtn = findViewById<Button>(R.id.btnLogin)

        loginBtn.setOnClickListener {
            val username = usernameEdit.text.toString()
            val password = passwordEdit.text.toString()
            lifecycleScope.launch {
                val result = ApiService.login(username, password)
                if (result.success) {
                    // Simpan sesi login di SharedPreferences
                    prefs.edit().putString("username", username).apply()
                    Toast.makeText(this@LoginActivity, "Login sukses", Toast.LENGTH_SHORT).show()
                    // Pindah ke HomeActivity
                    startActivity(android.content.Intent(this@LoginActivity, com.qts.qbilling.ui.HomeActivity::class.java))
                    finish()
                } else {
                    Toast.makeText(this@LoginActivity, result.error ?: "Login gagal", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }
}
