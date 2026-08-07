<!DOCTYPE html>
<html lang="id">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Akun Anda Terisolir - Informasi Penting</title>
    <?php include '../koneksidb.php'; $config = json_decode(file_get_contents('../config.json'), true); ?>
    <style>
      :root {
        --primary-color: <?php echo isset($config['extracted_primary_color']) ? $config['extracted_primary_color'] : '#f68013'; ?>;
        --secondary-color: <?php echo isset($config['extracted_secondary_color']) ? $config['extracted_secondary_color'] : '#f68012'; ?>;
        --accent-color: #FFA726;
      }
      * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color), var(--accent-color));
        background-size: 400% 400%;
        animation: gradientBG 15s ease infinite;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 20px;
      }

      @keyframes gradientBG {
        0% {
          background-position: 0% 50%;
        }
        50% {
          background-position: 100% 50%;
        }
        100% {
          background-position: 0% 50%;
        }
      }

      .container {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 15px;
        padding: 40px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        max-width: 600px;
        width: 100%;
        text-align: center;
        backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
      }

      .container:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
      }

      .warning-icon {
        font-size: 60px;
        margin-bottom: 15px;
        animation: bounce 2s infinite;
      }

      @keyframes bounce {
        0%, 100% {
          transform: translateY(0);
        }
        50% {
          transform: translateY(-10px);
        }
      }

      h1 {
        color: #dc3545;
        margin: 15px 0 10px 0;
        font-size: 32px;
        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.1);
      }

      .subtitle {
        color: #666;
        font-size: 16px;
        margin-bottom: 30px;
        line-height: 1.6;
      }

      .info-card {
        background: rgba(255, 255, 255, 0.7);
        border-left: 5px solid var(--primary-color);
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        text-align: left;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      }

      .info-card h3 {
        color: var(--primary-color);
        margin-top: 0;
        font-size: 18px;
      }

      .info-card p {
        color: #555;
        margin: 10px 0;
        font-size: 15px;
        line-height: 1.6;
      }

      .info-card ul {
        text-align: left;
        color: #555;
        margin: 10px 0;
        padding-left: 20px;
      }

      .info-card li {
        margin-bottom: 8px;
        line-height: 1.6;
      }

      .reason-box {
        background: #fff3cd;
        border-left: 5px solid #ffc107;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 25px;
        text-align: left;
      }

      .reason-box strong {
        display: block;
        color: #856404;
        margin-bottom: 8px;
      }

      .reason-box p {
        color: #856404;
        margin: 0;
        font-size: 14px;
      }

      .steps {
        background: rgba(52, 152, 219, 0.1);
        border-left: 5px solid #3498db;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        text-align: left;
      }

      .steps h3 {
        color: #3498db;
        margin-top: 0;
        font-size: 18px;
      }

      .steps ol {
        margin: 10px 0;
        padding-left: 20px;
        color: #555;
      }

      .steps li {
        margin-bottom: 10px;
        line-height: 1.6;
      }

      .highlight {
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        font-weight: 600;
      }

      button {
        background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        color: white;
        border: none;
        padding: 16px 30px;
        margin: 10px 0;
        font-size: 16px;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        font-weight: 600;
        box-shadow: 0 4px 6px rgba(255, 122, 0, 0.2);
        transition: all 0.3s ease;
      }

      button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 10px rgba(255, 122, 0, 0.3);
        background: linear-gradient(to right, #ff9500, #ff7a00);
      }

      button:active {
        transform: translateY(0);
      }

      .contact-info {
        margin-top: 25px;
        padding-top: 25px;
        border-top: 2px solid #e0e0e0;
        color: #666;
        font-size: 14px;
      }

      .contact-info p {
        margin: 8px 0;
      }

      .contact-info strong {
        color: var(--primary-color);
      }

      @media (max-width: 480px) {
        .container {
          padding: 25px;
        }

        h1 {
          font-size: 26px;
        }

        .warning-icon {
          font-size: 48px;
        }

        .subtitle {
          font-size: 14px;
        }

        button {
          padding: 14px 20px;
          font-size: 15px;
        }
      }
    </style>
  </head>
  <body>
    <div class="container">
      <div class="warning-icon">⚠️</div>
      <h1>Akun Anda Terisolir</h1>
      <p class="subtitle">Koneksi internet Anda telah diputus sementara karena alasan pembayaran</p>

     

      <div class="steps">
        <h3>✅ Cara Mengaktifkan Kembali</h3>
        <ol>
          <li>Klik tombol <span class="highlight">"Bayar Sekarang"</span> di bawah</li>
          <li>Masuk ke portal pembayaran dengan Username dan Password Anda</li>
          <li>Pilih metode pembayaran yang tersedia</li>
          <li>Lakukan pembayaran sesuai dengan tagihan yang tertera</li>
          <li>Tunggu konfirmasi pembayaran (biasanya 1-24 jam)</li>
        </ol>
      </div>

      <button onclick="window.location.href='https://quenbytekniksejahtera.com/crm/billing/broadband/portallogin.php'">
        💳 Bayar Sekarang
      </button>

      </div>
    </div>
  </body>
</html>
