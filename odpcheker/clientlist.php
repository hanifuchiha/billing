<?php
require "../../employee/cek-sesi.php";
require "../../employee/koneksibilling.php";
$idserver = $_GET['idserver'];
$kodeodp = $_GET['kodeodp'];
if ($idserver == "") {
    header("location:serverlist.php");
}
$sql = 'SELECT * FROM server WHERE id=' . $idserver;
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_array($query)) {
    $server = $data['AREA'];
    $user = $data['PEMILIK'];
    $ip = $data['IP'];
    $password = $data['PASSWORD'];
    $map = $data['MAP'];
    $waapi = $data['BOTWA'];
    $olt = $data['OLT'];
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link href="../vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <title>ODP LIST - <?php echo $kodeodp ?></title>
    <style>
        :root {
            --primary: #3498db;
            --secondary: #f39c12;
            --light: #f8f9fa;
            --dark: #343a40;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 5px 0;
            min-height: 100vh;
        }

        .client-section {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            overflow: visible;
            width: 100%;
            margin: 0 0 10px 0;
            padding: 0;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .client-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
        }

        .client-header {
            background: linear-gradient(135deg, var(--primary), #2980b9);
            color: white;
            padding: 15px 20px;
            border-bottom: none;
        }

        .client-body {
            padding: 15px;
            background-color: white;
        }

        h1 {
            font-size: 1.8rem;
            color: var(--dark);
            margin-bottom: 20px;
            font-weight: 600;
        }

        h1 span {
            color: var(--primary);
        }

        .btn-danger {
            background-color: #e74c3c;
            border-color: #e74c3c;
        }

        .alert-warning {
            background-color: rgba(243, 156, 18, 0.1);
            border-color: rgba(243, 156, 18, 0.3);
            color: #856404;
        }

        .table {
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            font-size: 0.8em;
        }

        .table thead th {
            background-color: var(--primary);
            color: white;
            border: none;
            border-right: 1px solid rgba(255,255,255,0.3);
            padding: 8px 10px;
            font-weight: bold;
            text-align: center;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .table tbody tr {
            transition: all 0.2s ease;
        }

        .table tbody tr:nth-child(even) {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .table tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.1);
            transform: translateX(2px) scale(1.01);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .table tbody td {
            border-bottom: 1px solid #e0e6ed;
            border-right: 1px solid #e0e6ed;
            padding: 8px 10px;
            vertical-align: middle;
            word-wrap: break-word;
            white-space: normal;
            overflow: visible;
            transition: all 0.2s ease;
        }

        .table tbody td:first-child {
            text-align: center;
            font-weight: 500;
            min-width: 50px;
        }

        .table tbody td:nth-child(2) {
            text-align: left;
            min-width: 200px;
            font-weight: 500;
        }

        .table tbody td:nth-child(3) {
            text-align: center;
            min-width: 100px;
        }

        .table tbody td:last-child {
            text-align: center;
            min-width: 150px;
        }

        .status-online {
            color: #27ae60;
            font-weight: 500;
        }

        .status-offline {
            color: #e74c3c;
            font-weight: 500;
        }

        .power-indicator {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85em;
        }

        .power-good {
            background-color: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .power-los {
            background-color: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .power-unknown {
            background-color: rgba(149, 165, 166, 0.1);
            color: #95a5a6;
            border: 1px solid rgba(149, 165, 166, 0.3);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85em;
            margin-bottom: 2px;
        }

        .status-online {
            background-color: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .status-offline {
            background-color: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .status-expired {
            background-color: rgba(243, 156, 18, 0.1);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        #loading {
            padding: 20px;
            text-align: center;
            color: var(--secondary);
            font-size: 1.1rem;
        }

        .breadcrumb {
            background-color: transparent;
            padding: 0;
            font-size: 0.9rem;
        }

        .breadcrumb-item.active {
            color: var(--secondary);
            font-weight: 500;
        }

        .btn-group .btn {
            border-radius: 20px;
            padding: 5px 15px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: all 0.2s ease;
        }

        .btn-group .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .client-section {
                border-radius: 10px;
                margin: 0 0 15px 0;
            }
            .client-body {
                padding: 8px;
            }
            .client-header {
                padding: 10px 15px;
            }
            .table-responsive {
                font-size: 0.7em;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .btn-group .btn {
                padding: 4px 10px;
                font-size: 0.8em;
            }
        }
    </style>
</head>

<body>
    <div class="client-section">
        <div class="client-header">
            <h5 class="mb-0"><i class="fas fa-users mr-2"></i>ODP Monitoring</h5>
        </div>
        <div class="client-body">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="serverlist.php"><i class="fas fa-server mr-1"></i>Servers</a></li>
                    <li class="breadcrumb-item"><a href="odplist.php?idserver=<?php echo $idserver ?>"><i class="fas fa-network-wired mr-1"></i><?php echo $server ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-map-marker-alt mr-1"></i><?php echo $kodeodp ?></li>
                </ol>
            </nav>

            <div class="alert alert-warning d-flex align-items-center" role="alert">
                <i class="fas fa-info-circle mr-2"></i>
                <div>
                    Data updates in <strong>real-time</strong> - no refresh needed
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Customer</th>
                            <th>Power Ddm</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="loading">
                            <td colspan="4" class="text-center py-4">
                                <div class="spinner-border text-warning" role="status">
                                    <span class="sr-only">Loading...</span>
                                </div>
                                <p class="mt-2 mb-0">Loading customer data, please wait...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <!-- jQuery and Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>



    <script>
        function fetchData() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'fetch_data.php?idserver=<?php echo $idserver ?>&kodeodp=<?php echo $kodeodp ?>', true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    document.querySelector('#dataTable tbody').innerHTML = xhr.responseText;
                    // Initialize DataTable after data is loaded
                    $('#dataTable').DataTable({
                        "pageLength": 25,
                        "order": [
                            [0, "asc"]
                        ]
                    });
                }
            };
            xhr.send();
        }

        // Load data immediately and then every 5 seconds
        fetchData();
        setInterval(fetchData, 5000);
    </script>
</body>

</html>