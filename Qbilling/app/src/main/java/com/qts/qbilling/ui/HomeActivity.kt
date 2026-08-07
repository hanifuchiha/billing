package com.qts.qbilling.ui

import android.content.Intent
import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.ListView
import androidx.appcompat.app.AppCompatActivity
import com.qts.qbilling.ApiServiceAll
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.json.JSONArray

class HomeActivity : AppCompatActivity() {
    // Menu utama, urut dan grouping sesuai sidebar web
    private val menuItems = listOf(
        // --- DASHBOARD ---
        "Dasbor" to suspend { ApiServiceAll.getDashboard() },
        "Live Chat" to suspend { ApiServiceAll.getLivechat() },
        "Manajer Tiket" to suspend { ApiServiceAll.getTiket() },
        "Pemantauan Tiket" to suspend { ApiServiceAll.getMonitoring() },

        // --- INFRASTRUKTUR JARINGAN ---
        "Koneksi VPN" to suspend { ApiServiceAll.getVpn() },
        "Area Server" to suspend { ApiServiceAll.getServer() },
        "IP Pool" to suspend { ApiServiceAll.getPool() },
        "Mapping ODP" to suspend { ApiServiceAll.getOdp() },
        "OLT" to suspend { ApiServiceAll.getOlt() },
        "NMS" to suspend { ApiServiceAll.getNms() },
        "Insfrastruktur maps" to suspend { ApiServiceAll.getRaw("ftth_maps.php").optJSONArray("data") ?: JSONArray() },

        // --- PPP MENU ---
        "Customer PPPOE" to suspend { ApiServiceAll.getRaw("tables.php").optJSONArray("data") ?: JSONArray() },
        "Provisioning Joblist" to suspend { ApiServiceAll.getRaw("provisioning_approval.php").optJSONArray("data") ?: JSONArray() },
        "Paket PPPOE" to suspend { ApiServiceAll.getRaw("packages.php").optJSONArray("data") ?: JSONArray() },

        // --- HOTSPOT MENU ---
        "Customer Hotspot" to suspend { ApiServiceAll.getRaw("tableshotspot.php").optJSONArray("data") ?: JSONArray() },
        "Paket Hotspot" to suspend { ApiServiceAll.getRaw("packageshotspot.php").optJSONArray("data") ?: JSONArray() },
        "Voucher Generator" to suspend { ApiServiceAll.getRaw("vouchergenerator.php").optJSONArray("data") ?: JSONArray() },
        "Voucher Bank" to suspend { ApiServiceAll.getRaw("voucherbank.php").optJSONArray("data") ?: JSONArray() },

        // --- KEMITRAAN & KOMISI ---
        "Mitra accounts" to suspend { ApiServiceAll.getRaw("mitraadmin.php").optJSONArray("data") ?: JSONArray() },
        "Komisi paket setting" to suspend { ApiServiceAll.getRaw("commissionsetting.php").optJSONArray("data") ?: JSONArray() },
        "Pembayaran komisi" to suspend { ApiServiceAll.getRaw("rekappembayaranmitra.php").optJSONArray("data") ?: JSONArray() },

        // --- INTEGRASI ACS ---
        "Informasi Server ACS" to suspend { ApiServiceAll.getRaw("acs_server_info.php").optJSONArray("data") ?: JSONArray() },

        // --- KEUANGAN ---
        "Transaksi" to suspend { ApiServiceAll.getTransaksi() },
        "Diskon" to suspend { ApiServiceAll.getRaw("diskon.php").optJSONArray("data") ?: JSONArray() },
        "Tambahan Biaya" to suspend { ApiServiceAll.getRaw("biaya_tambahan.php").optJSONArray("data") ?: JSONArray() },
        "Laporan pengeluaran" to suspend { ApiServiceAll.getRaw("pengeluaran.php").optJSONArray("data") ?: JSONArray() },
        "Statistik dan Laporan" to suspend { ApiServiceAll.getRaw("statistics.php").optJSONArray("data") ?: JSONArray() },

        // --- MENU PELANGGAN ---
        "Broadcast info" to suspend { ApiServiceAll.getRaw("broadcast.php").optJSONArray("data") ?: JSONArray() },
        "Pelanggan menunggak" to suspend { ApiServiceAll.getRaw("pelanggan_menunggak.php").optJSONArray("data") ?: JSONArray() },
        "Pelanggan berhenti" to suspend { ApiServiceAll.getRaw("daftar_pelanggan_berhenti.php").optJSONArray("data") ?: JSONArray() },
        "Broadband login" to suspend { ApiServiceAll.getRaw("broadband/portallogin.php").optJSONArray("data") ?: JSONArray() },
        "Login hotspot billing" to suspend { ApiServiceAll.getRaw("login_hotspot_billing.php").optJSONArray("data") ?: JSONArray() },

        // --- AKUN & PENGATURAN ---
        "Notification settings" to suspend { ApiServiceAll.getNotifikasi() },
        "Log History" to suspend { ApiServiceAll.getLog() },
        "Backup & Restore" to suspend { ApiServiceAll.getRaw("backup_restore.php").optJSONArray("data") ?: JSONArray() },
        "Whatsapp BOT" to suspend { ApiServiceAll.getRaw("wabot.php").optJSONArray("data") ?: JSONArray() },
        "API Integrasi" to suspend { ApiServiceAll.getRaw("settingsapi.php").optJSONArray("data") ?: JSONArray() },
        "Payment Setting" to suspend { ApiServiceAll.getRaw("paymentset.php").optJSONArray("data") ?: JSONArray() },
        "Profile and Account" to suspend { ApiServiceAll.getRaw("user.php").optJSONArray("data") ?: JSONArray() },
        "Log out" to suspend { JSONArray() },

        // --- ADMINISTRATOR PANEL (khusus admin) ---
        "Live chat super admin" to suspend { ApiServiceAll.getRaw("livechatadmin.php").optJSONArray("data") ?: JSONArray() },
        "User sewa billing" to suspend { ApiServiceAll.getRaw("crmadmin.php").optJSONArray("data") ?: JSONArray() },
        "User Global maps" to suspend { ApiServiceAll.getRaw("crmglobalmap.php").optJSONArray("data") ?: JSONArray() },
        "Daftar Server ACS" to suspend { ApiServiceAll.getRaw("acs_servers_list.php").optJSONArray("data") ?: JSONArray() },
        "Tambah Server ACS" to suspend { ApiServiceAll.getRaw("acs_add_server.php").optJSONArray("data") ?: JSONArray() },
        "Isolir Forwarding" to suspend { ApiServiceAll.getRaw("isolir_forwarding.php").optJSONArray("data") ?: JSONArray() },
        "Radius setting" to suspend { ApiServiceAll.getRaw("radius.php").optJSONArray("data") ?: JSONArray() },
        "OTP Sign Up Billing" to suspend { ApiServiceAll.getRaw("otp_signup_settings.php").optJSONArray("data") ?: JSONArray() },
        "Panel setting" to suspend { ApiServiceAll.getRaw("../panel/").optJSONArray("data") ?: JSONArray() }
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val listView = ListView(this)
        setContentView(listView)
        val adapter = ArrayAdapter(this, android.R.layout.simple_list_item_1, menuItems.map { it.first })
        listView.adapter = adapter
        listView.setOnItemClickListener { _, _, position, _ ->
            val (title, loader) = menuItems[position]
            val intent = Intent(this, GenericListActivity::class.java)
            intent.putExtra("title", title)
            intent.putExtra("loaderKey", title)
            startActivity(intent)
        }
    }
}
