<?php
require "../../employee/cek-sesi.php";
require "../../employee/koneksibilling.php";
$idserver = $_GET['idserver'];
if ($idserver == "") {
  header("location:serverlist.php");
}
$sql = 'SELECT * FROM server WHERE id=' . $idserver . ' ';
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
  <title>ODP LIST - <?php echo $server ?></title>

  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link href="../vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

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

    .odp-section {
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

    .odp-section:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
    }

    .odp-header {
      background: linear-gradient(135deg, var(--primary), #2980b9);
      color: white;
      padding: 15px 20px;
      border-bottom: none;
    }

    .odp-body {
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

    .btn-dark {
      background-color: var(--dark);
      border-color: var(--dark);
    }

    .btn-dark:hover {
      background-color: #23272b;
      border-color: #1d2124;
    }

    .table {
      border-collapse: collapse;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      font-size: 0.9em;
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
      text-align: left;
      font-weight: 500;
      min-width: 200px;
      background-color: rgba(52, 152, 219, 0.05);
      font-weight: bold;
      color: var(--primary);
    }

    .table tbody td:last-child {
      text-align: center;
      min-width: 80px;
    }

    .table .btn {
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
      transition: all 0.2s ease;
    }

    .table .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 3px 8px rgba(0,0,0,0.3);
    }

    .action-btn {
      border-radius: 20px;
      padding: 5px 15px;
      font-weight: 500;
      min-width: 80px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transition: all 0.3s ease;
      background: linear-gradient(135deg, var(--primary), #2980b9);
      color: white;
      border: none;
    }

    .action-btn:hover {
      transform: translateY(-2px) scale(1.05);
      box-shadow: 0 6px 15px rgba(0,0,0,0.3);
      background: linear-gradient(135deg, #2980b9, var(--primary));
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
      margin-right: 5px;
      min-width: 80px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transition: all 0.2s ease;
    }

    .btn-group .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }

    /* Mobile optimizations */
    @media (max-width: 768px) {
      .odp-section {
        border-radius: 10px;
        margin: 0 0 15px 0;
      }
      .odp-body {
        padding: 8px;
      }
      .odp-header {
        padding: 10px 15px;
      }
      .table-responsive {
        font-size: 0.8em;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      .action-btn {
        padding: 4px 10px;
        font-size: 0.8em;
        min-width: 70px;
      }
    }
  </style>
</head>

<body>
  <div class="odp-section">
      <div class="odp-header">
        <h5 class="mb-0"><i class="fas fa-network-wired mr-2"></i>ODP List - <?php echo $server ?></h5>
      </div>
      <div class="odp-body">
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="serverlist.php"><i class="fas fa-server mr-1"></i>Servers</a></li>
            <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-network-wired mr-1"></i><?php echo $server ?></li>
          </ol>
        </nav>

        <div class="d-flex justify-content-between mb-3">
          <div class="btn-group">
            <a href="serverlist.php" class="btn btn-danger action-btn">
              <i class="fas fa-arrow-left mr-1"></i>Back
            </a>
            <button onClick="document.location.reload(true)" class="btn btn-dark action-btn">
              <i class="fas fa-sync-alt mr-1"></i>Refresh
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover" id="dataTable" width="100%" cellspacing="0">
            <thead>
              <tr>
                <th>ODP ID / Name</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $sql1 = "SELECT * from `odp` WHERE `PEMILIK` = '$user' and area='$server'";
              $query1 = mysqli_query($conn, $sql1);

              while ($data1 = mysqli_fetch_array($query1)) {
                echo '<tr>';
                echo '<td><strong>' . $data1['KODE'] . '</strong> ' . $data1['NAME'] . '</td>';
                echo '<td>';
                echo '<a class="btn btn-primary btn-sm action-btn" href="clientlist.php?idserver=' . $idserver . '&kodeodp=' . $data1['KODE'] . '">';
                echo '<i class="fas fa-external-link-alt mr-1"></i> Open';
                echo '</a>';
                echo '</td>';
                echo '</tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
  </div>

  <!-- jQuery and Bootstrap Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

  <!-- DataTables -->
  <script src="../vendor/datatables/jquery.dataTables.min.js"></script>
  <script src="../vendor/datatables/dataTables.bootstrap4.min.js"></script>
  <script src="../js/demo/datatables-demo.js"></script>

  <script>
    $(document).ready(function() {
      $('#dataTable').DataTable({
        "pageLength": 25,
        "order": [
          [0, "asc"]
        ],
        "responsive": true
      });
    });
  </script>
</body>

</html>