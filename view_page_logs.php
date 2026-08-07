<?php require 'header.php'; ?>

<body class="g-sidenav-show bg-gray-100">
  <?php require 'sidebar.php'; ?>
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg">
    <?php require 'navbar.php'; ?>

    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card mb-4">
            <div class="card-header pb-0">
              <h6>Log Akses Halaman</h6>
              <p class="text-sm mb-0">Daftar aktivitas akses halaman dari semua pengguna</p>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
              <!-- Filter Section -->
              <div class="px-4 py-3">
                <form method="GET" action="" class="row g-3">
                  <div class="col-md-3">
                    <label class="form-label text-xs">Username</label>
                    <input type="text" name="filter_username" class="form-control form-control-sm" 
                           placeholder="Filter username" value="<?= htmlspecialchars($_GET['filter_username'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label text-xs">Halaman</label>
                    <input type="text" name="filter_page" class="form-control form-control-sm" 
                           placeholder="Filter halaman" value="<?= htmlspecialchars($_GET['filter_page'] ?? '') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label text-xs">Tanggal</label>
                    <input type="date" name="filter_date" class="form-control form-control-sm" 
                           value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label text-xs">Limit</label>
                    <select name="limit" class="form-control form-control-sm">
                      <option value="50" <?= ($_GET['limit'] ?? '50') == '50' ? 'selected' : '' ?>>50</option>
                      <option value="100" <?= ($_GET['limit'] ?? '50') == '100' ? 'selected' : '' ?>>100</option>
                      <option value="500" <?= ($_GET['limit'] ?? '50') == '500' ? 'selected' : '' ?>>500</option>
                      <option value="1000" <?= ($_GET['limit'] ?? '50') == '1000' ? 'selected' : '' ?>>1000</option>
                    </select>
                  </div>
                  <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                  </div>
                </form>
              </div>

              <div class="table-responsive p-0">
                <table class="table align-items-center mb-0">
                  <thead>
                    <tr>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">No</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Username</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Halaman</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">IP Address</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Method</th>
                      <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Referer</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    // Build query dengan filter
                    $where_clauses = [];
                    $params = [];
                    
                    if (!empty($_GET['filter_username'])) {
                        $where_clauses[] = "username LIKE '%" . mysqli_real_escape_string($conn, $_GET['filter_username']) . "%'";
                    }
                    
                    if (!empty($_GET['filter_page'])) {
                        $where_clauses[] = "page_name LIKE '%" . mysqli_real_escape_string($conn, $_GET['filter_page']) . "%'";
                    }
                    
                    if (!empty($_GET['filter_date'])) {
                        $where_clauses[] = "DATE(access_time) = '" . mysqli_real_escape_string($conn, $_GET['filter_date']) . "'";
                    }
                    
                    // Jika bukan admin, hanya tampilkan log user sendiri
                    if ($AKSES != 'ADMIN') {
                        $where_clauses[] = "user_id = '$current_user_id'";
                    }
                    
                    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
                    
                    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
                    $limit_sql = "LIMIT $limit";
                    
                    $query = "SELECT * FROM page_access_log $where_sql ORDER BY access_time DESC $limit_sql";
                    $result = mysqli_query($conn, $query);
                    
                    if ($result && mysqli_num_rows($result) > 0) {
                        $no = 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $waktu = date('d/m/Y H:i:s', strtotime($row['access_time']));
                            $page_display = basename($row['page_name']);
                            $referer_display = !empty($row['referer']) ? basename($row['referer']) : '-';
                            ?>
                            <tr>
                              <td class="text-xs font-weight-bold ps-4"><?= $no++ ?></td>
                              <td class="text-xs"><?= htmlspecialchars($waktu) ?></td>
                              <td class="text-xs font-weight-bold"><?= htmlspecialchars($row['username']) ?></td>
                              <td class="text-xs">
                                <span class="badge badge-sm bg-gradient-info" title="<?= htmlspecialchars($row['page_url']) ?>">
                                  <?= htmlspecialchars($page_display) ?>
                                </span>
                              </td>
                              <td class="text-xs"><?= htmlspecialchars($row['ip_address']) ?></td>
                              <td class="text-xs">
                                <span class="badge badge-sm <?= $row['method'] == 'POST' ? 'bg-gradient-warning' : 'bg-gradient-secondary' ?>">
                                  <?= htmlspecialchars($row['method']) ?>
                                </span>
                              </td>
                              <td class="text-xs"><?= htmlspecialchars(substr($referer_display, 0, 30)) ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                          <td colspan="7" class="text-center text-xs py-4">Tidak ada data log</td>
                        </tr>
                        <?php
                    }
                    ?>
                  </tbody>
                </table>
              </div>
              
              <!-- Statistik -->
              <div class="px-4 py-3">
                <?php
                $stats_query = "SELECT 
                    COUNT(*) as total_access,
                    COUNT(DISTINCT username) as total_users,
                    COUNT(DISTINCT page_name) as total_pages,
                    COUNT(DISTINCT ip_address) as total_ips
                    FROM page_access_log
                    $where_sql";
                $stats_result = mysqli_query($conn, $stats_query);
                if ($stats_result) {
                    $stats = mysqli_fetch_assoc($stats_result);
                    ?>
                    <div class="row">
                      <div class="col-md-3">
                        <div class="card">
                          <div class="card-body p-3 text-center">
                            <div class="text-sm mb-0 text-capitalize font-weight-bold">Total Akses</div>
                            <h5 class="mb-0"><?= number_format($stats['total_access']) ?></h5>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="card">
                          <div class="card-body p-3 text-center">
                            <div class="text-sm mb-0 text-capitalize font-weight-bold">Total User</div>
                            <h5 class="mb-0"><?= number_format($stats['total_users']) ?></h5>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="card">
                          <div class="card-body p-3 text-center">
                            <div class="text-sm mb-0 text-capitalize font-weight-bold">Total Halaman</div>
                            <h5 class="mb-0"><?= number_format($stats['total_pages']) ?></h5>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="card">
                          <div class="card-body p-3 text-center">
                            <div class="text-sm mb-0 text-capitalize font-weight-bold">Total IP</div>
                            <h5 class="mb-0"><?= number_format($stats['total_ips']) ?></h5>
                          </div>
                        </div>
                      </div>
                    </div>
                    <?php
                }
                ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php require 'footer.php'; ?>
  </main>
</body>
</html>
