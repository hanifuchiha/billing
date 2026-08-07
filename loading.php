<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Memuat Dashboard...</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa, #dee2e6);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            font-family: sans-serif;
            margin: 0;
        }

        .loader {
            text-align: center;
        }

        .spinner {
            border: 8px solid #f3f3f3;
            border-top: 8px solid #3498db;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .text {
            font-size: 18px;
            color: #555;
        }

        iframe {
            display: none;
            /* sembunyikan iframe */
        }
    </style>
</head>

<body>
    <div class="loader">
        <div class="spinner"></div>
        <div class="text">Mohon tunggu, dashboard sedang dimuat...</div>
    </div>

    <!-- Iframe tersembunyi untuk preload -->
    <iframe src="dashboard.php" onload="redirectToDashboard()" id="dashFrame"></iframe>

    <script>
        function redirectToDashboard() {
            // Setelah iframe selesai load, arahkan user ke dashboard sebenarnya
            window.location.href = 'dashboard.php';
        }
    </script>
</body>

</html>