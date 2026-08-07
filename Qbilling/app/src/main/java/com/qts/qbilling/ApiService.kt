package com.qts.qbilling

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object ApiService {
    private const val BASE_URL = "https://quenbytekniksejahtera.com/crm/billing/api/"

    data class LoginResult(val success: Boolean, val error: String? = null)

    suspend fun login(username: String, password: String): LoginResult = withContext(Dispatchers.IO) {
        val url = URL(BASE_URL + "login.php")
        val conn = url.openConnection() as HttpURLConnection
        conn.requestMethod = "POST"
        conn.setRequestProperty("Content-Type", "application/json")
        conn.doOutput = true
        val json = JSONObject()
        json.put("username", username)
        json.put("password", password)
        conn.outputStream.use { it.write(json.toString().toByteArray()) }
        val response = conn.inputStream.bufferedReader().readText()
        val obj = JSONObject(response)
        if (obj.optBoolean("success")) {
            LoginResult(true)
        } else {
            LoginResult(false, obj.optString("error"))
        }
    }
}
