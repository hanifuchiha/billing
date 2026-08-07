








  <?php
  // Info perusahaan diambil dari config.json (sudah di-load sbg $config oleh header.php,
  // lihat crm/billing/header.php baris ~191-196) -- BUKAN parser baru, pakai variabel yg
  // sudah ada supaya footer selalu sinkron dgn config.json tanpa perlu diedit manual lagi.
  $footerConfig = isset($config) && is_array($config) ? $config : [];
  $footerPerusahaan = trim((string)($footerConfig['perusahaan'] ?? ''));
  $footerWhatsapp = trim((string)($footerConfig['whatsapp'] ?? ''));
  $footerEmail = trim((string)($footerConfig['contact_email'] ?? ''));
  $footerSlogan = trim((string)($footerConfig['slogan_perusahaan'] ?? ''));
  $footerDesigner = trim((string)($footerConfig['DIREKTUR'] ?? ''));
  ?>
  <footer class="footer pt-3  ">
    <div class="container-fluid">
      <div class="row align-items-center justify-content-lg-between">
        <div class="col-lg-6 mb-lg-0 mb-4">
          <div class="copyright text-center text-sm text-muted text-lg-start">
            © <script>
              document.write(new Date().getFullYear())
            </script><?php if ($footerPerusahaan !== ''): ?>,
           Powered by <?= htmlspecialchars($footerPerusahaan) ?><?= $footerSlogan !== '' ? ' - ' . htmlspecialchars($footerSlogan) : '' ?><?php endif; ?><?php if ($footerDesigner !== ''): ?> | Design by <?= htmlspecialchars($footerDesigner) ?><?php endif; ?>

          </div>
        </div>
        <div class="col-lg-6">
          <div class="copyright text-center text-sm text-muted text-lg-end">
            <?php if ($footerWhatsapp !== ''): ?>
              WA: <a href="https://wa.me/<?= rawurlencode($footerWhatsapp) ?>" target="_blank" rel="noopener" class="text-muted"><?= htmlspecialchars($footerWhatsapp) ?></a>
            <?php endif; ?>
            <?php if ($footerEmail !== ''): ?>
              <?= $footerWhatsapp !== '' ? ' &middot; ' : '' ?><a href="mailto:<?= htmlspecialchars($footerEmail) ?>" class="text-muted"><?= htmlspecialchars($footerEmail) ?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </footer>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      let isInIframe = false;
      try {
        isInIframe = window.self !== window.top;
      } catch (e) {
        isInIframe = true;
      }
      if (!isInIframe) return;

      document.querySelectorAll('footer .copyright').forEach(function (el) {
        el.style.display = 'none';
      });
    });
  </script>
  </div>
  </main>

  <!--   Core JS Files   -->
  <script src="../assets/js/core/popper.min.js"></script>
  <script src="../assets/js/core/bootstrap.min.js"></script>
  <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script src="../assets/js/plugins/chartjs.min.js"></script>
  <script>
    var chartBarsEl = document.getElementById("chart-bars");
    if (chartBarsEl) {
      var ctx = chartBarsEl.getContext("2d");

      new Chart(ctx, {
        type: "bar",
        data: {
          labels: ["Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
          datasets: [{
            label: "Sales",
            tension: 0.4,
            borderWidth: 0,
            borderRadius: 4,
            borderSkipped: false,
            backgroundColor: "#fff",
            data: [450, 200, 100, 220, 500, 100, 400, 230, 500],
            maxBarThickness: 6
          }, ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false,
            }
          },
          interaction: {
            intersect: false,
            mode: 'index',
          },
          scales: {
            y: {
              grid: {
                drawBorder: false,
                display: false,
                drawOnChartArea: false,
                drawTicks: false,
              },
              ticks: {
                suggestedMin: 0,
                suggestedMax: 500,
                beginAtZero: true,
                padding: 15,
                font: {
                  size: 14,
                  family: "Inter",
                  style: 'normal',
                  lineHeight: 2
                },
                color: "#fff"
              },
            },
            x: {
              grid: {
                drawBorder: false,
                display: false,
                drawOnChartArea: false,
                drawTicks: false
              },
              ticks: {
                display: false
              },
            },
          },
        },
      });
    }

    var chartLineEl = document.getElementById("chart-line");
    if (chartLineEl) {
      var ctx2 = chartLineEl.getContext("2d");

      var gradientStroke1 = ctx2.createLinearGradient(0, 230, 0, 50);

      gradientStroke1.addColorStop(1, 'rgba(203,12,159,0.2)');
      gradientStroke1.addColorStop(0.2, 'rgba(72,72,176,0.0)');
      gradientStroke1.addColorStop(0, 'rgba(203,12,159,0)');

      var gradientStroke2 = ctx2.createLinearGradient(0, 230, 0, 50);

      gradientStroke2.addColorStop(1, 'rgba(20,23,39,0.2)');
      gradientStroke2.addColorStop(0.2, 'rgba(72,72,176,0.0)');
      gradientStroke2.addColorStop(0, 'rgba(20,23,39,0)');

      new Chart(ctx2, {
        type: "line",
        data: {
          labels: ["Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
          datasets: [{
              label: "Mobile apps",
              tension: 0.4,
              borderWidth: 0,
              pointRadius: 0,
              borderColor: "#cb0c9f",
              borderWidth: 3,
              backgroundColor: gradientStroke1,
              fill: true,
              data: [50, 40, 300, 220, 500, 250, 400, 230, 500],
              maxBarThickness: 6

            },
            {
              label: "Websites",
              tension: 0.4,
              borderWidth: 0,
              pointRadius: 0,
              borderColor: "#3A416F",
              borderWidth: 3,
              backgroundColor: gradientStroke2,
              fill: true,
              data: [30, 90, 40, 140, 290, 290, 340, 230, 400],
              maxBarThickness: 6
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false,
            }
          },
          interaction: {
            intersect: false,
            mode: 'index',
          },
          scales: {
            y: {
              grid: {
                drawBorder: false,
                display: true,
                drawOnChartArea: true,
                drawTicks: false,
                borderDash: [5, 5]
              },
              ticks: {
                display: true,
                padding: 10,
                color: '#b2b9bf',
                font: {
                  size: 11,
                  family: "Inter",
                  style: 'normal',
                  lineHeight: 2
                },
              }
            },
            x: {
              grid: {
                drawBorder: false,
                display: false,
                drawOnChartArea: false,
                drawTicks: false,
                borderDash: [5, 5]
              },
              ticks: {
                display: true,
                color: '#b2b9bf',
                padding: 20,
                font: {
                  size: 11,
                  family: "Inter",
                  style: 'normal',
                  lineHeight: 2
                },
              }
            },
          },
        },
      });
    }
  </script>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const navLinks = document.querySelectorAll(".nav-link");

      navLinks.forEach((link) => {
        // Event ketika mouse masuk (hover)
        link.addEventListener("mouseenter", function() {
          this.classList.add("active");
        });

        // Event ketika mouse keluar
        link.addEventListener("mouseleave", function() {
          this.classList.remove("active");
        });

        // Event ketika link diklik
        link.addEventListener("click", function(event) {
          // Hapus active dari semua link
          navLinks.forEach((el) => el.classList.remove("active"));

          // Tambahkan active ke yang diklik
          this.classList.add("active");

          // Jalankan navigasi ke href
          const href = this.getAttribute("href");
          if (href && href !== "#") {
            window.location.href = href;
          }
        });
      });
    });
  </script>
  <script>
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }
  </script>
  <!-- Github buttons -->
  <script async defer src="https://buttons.github.io/buttons.js"></script>
  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
</body>

</html>