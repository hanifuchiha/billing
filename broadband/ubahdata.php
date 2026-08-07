<?php
include '../koneksibilling.php';
$idcs = $_GET['idselect'] ?? '';
$notif = $_GET['notif'] ?? '';
$cek1 = $_GET['KIRIM'] ?? '';

if ($cek1 === "SIMPAN") {
	$Nama = $_GET['Nama'] ?? '';
	$Alamat = $_GET['Alamat'] ?? '';
	$Tikor = $_GET['Tikor'] ?? '';
	$nowa = $_GET['nowa'] ?? '';
	$EMAIL = $_GET['email'] ?? '';
	$IDPEL = $_GET['IDPEL'] ?? '';

	if ($Nama && $Alamat && $nowa && $EMAIL) {
		$sql = "UPDATE pelanggan SET NAMA='$Nama', ALAMAT='$Alamat', NOWA='$nowa', EMAIL='$EMAIL', TIKOR='$Tikor' WHERE IDPEL='$IDPEL'";
		if ($conn->query($sql) === TRUE) {
			// Set success notification
			$notif = "BERHASIL UBAH DATA";
			$success = true;
		} else {
			$notif = "Gagal menyimpan perubahan.";
		}
	} else {
		$notif = "Data belum lengkap, mohon lengkapi semua field.";
	}
}

$data1 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM pelanggan WHERE IDPEL='$idcs' OR NOWA='$idcs'"));
?>

<!DOCTYPE html>
<html lang="id">

<head>
<?php
	$logo_path = "../../../dokumen/logo/profile-$useraccount.png";
	if (!file_exists($logo_path)) {
		$logo_path = "../../../dokumen/logo/logo.png";
	}
?>
	<meta charset="UTF-8">
	<title>Ubah Data Pelanggan | QTS</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
	<style>
		:root {
			--primary-green: #0d6efd;
			--dark-green: #0a58ca;
			--orange: #F7941D;
			--white: #ffffff;
			--light-gray: #f8f9fa;
			--border-color: #e9ecef;
		}

		body {
			font-family: 'Poppins', sans-serif;
			background-color: var(--light-gray);
			color: #333;
		}

		.form-container {
			max-width: 600px;
			margin: 30px auto;
			background: var(--white);
			padding: 25px;
			border-radius: 10px;
			box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
		}

		.btn-qts {
			background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
			color: white;
			border: none;
			border-radius: 30px;
			padding: 10px 20px;
			transition: all 0.3s ease;
		}

		.btn-qts:hover {
			background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
			transform: translateY(-1px);
			box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
			color: white;
		}

		.btn-secondary {
			background-color: var(--border-color);
			color: #666;
			border: 1px solid var(--border-color);
			border-radius: 30px;
			padding: 10px 20px;
			transition: all 0.3s ease;
		}

		.btn-secondary:hover {
			background-color: #dee2e6;
			color: #495057;
			border-color: #dee2e6;
		}

		h4 {
			text-align: center;
			font-weight: 600;
			margin-bottom: 20px;
			color: var(--primary-green);
		}

		.form-control {
			border: 1px solid var(--border-color);
			border-radius: 8px;
			transition: border-color 0.3s ease;
		}

		.form-control:focus {
			border-color: var(--primary-green);
			box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
		}

		label {
			color: var(--dark-green);
			font-weight: 500;
			margin-bottom: 5px;
		}

		.alert-info {
			background-color: #e8f5f4;
			border-color: var(--primary-green);
			color: var(--dark-green);
			border-radius: 8px;
		}

		.alert-success {
			background-color: #d4edda;
			border-color: #28a745;
			color: #155724;
			border-radius: 8px;
		}

		.alert-danger {
			background-color: #f8d7da;
			border-color: #dc3545;
			color: #721c24;
			border-radius: 8px;
		}

		/* Loading button state */
		.btn-loading {
			opacity: 0.6;
			pointer-events: none;
			position: relative;
		}

		.btn-loading::after {
			content: '';
			position: absolute;
			width: 16px;
			height: 16px;
			top: 50%;
			left: 50%;
			margin-left: -8px;
			margin-top: -8px;
			border: 2px solid transparent;
			border-top: 2px solid currentColor;
			border-radius: 50%;
			animation: button-loading-spinner 1s ease infinite;
		}

		@keyframes button-loading-spinner {
			from {
				transform: rotate(0turn);
			}
			to {
				transform: rotate(1turn);
			}
		}
	</style>
</head>
<body>
	<img src="<?= $logo_path ?>?v=<?= time() ?>" id="logoDynamic" alt="Logo" style="display:none;" />
	<!-- Dynamic color extraction from logo -->
	<script>
		// Extract dominant color from logo and set CSS variables
		function extractLogoColors(img) {
			if (!img || !img.complete) return;
			try {
				var canvas = document.createElement('canvas');
				var ctx = canvas.getContext('2d');
				canvas.width = img.naturalWidth || 120;
				canvas.height = img.naturalHeight || 120;
				ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
				var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
				var colorCounts = {};
				for (var i = 0; i < data.length; i += 12) {
					var r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
					if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
					var color = r+','+g+','+b;
					colorCounts[color] = (colorCounts[color]||0)+1;
				}
				var sorted = Object.entries(colorCounts).sort(function(a,b){return b[1]-a[1];});
				if (sorted.length) {
					var primary = sorted[0][0];
					var secondary = sorted[1] ? sorted[1][0] : primary;
					var accent = sorted[2] ? sorted[2][0] : primary;
					document.documentElement.style.setProperty('--primary-green', 'rgb('+primary+')');
					document.documentElement.style.setProperty('--orange', 'rgb('+secondary+')');
					document.documentElement.style.setProperty('--dark-green', 'rgb('+accent+')');
				}
			} catch(e) {console.log('Logo color extraction failed',e);}
		}
		window.addEventListener('DOMContentLoaded', function() {
			var img = document.getElementById('logoDynamic');
			if (img && img.complete) extractLogoColors(img);
			else if (img) img.onload = function(){extractLogoColors(img);};
		});
	</script>
</head>

<body>

	<div class="container">
		<div class="form-container">
			<h4>📝 Ubah Data Pelanggan</h4>

			<?php if ($notif): ?>
				<?php if (isset($success) && $success): ?>
					<div class="alert alert-success">
						<i class="bi bi-check-circle-fill"></i> <?php echo $notif; ?>
					</div>
				<?php else: ?>
					<div class="alert alert-danger">
						<i class="bi bi-exclamation-triangle-fill"></i> <?php echo $notif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="GET" action="ubahdata.php">
				<input type="hidden" name="IDPEL" value="<?php echo $data1['IDPEL']; ?>">
				<input type="hidden" name="idselect" value="<?php echo $data1['IDPEL']; ?>">
				<div class="form-group">
					<label>Nama Pelanggan</label>
					<input type="text" class="form-control" name="Nama" value="<?php echo $data1['NAMA']; ?>" required>
				</div>
				<div class="form-group">
					<label>Alamat</label>
					<input type="text" class="form-control" name="Alamat" value="<?php echo $data1['ALAMAT']; ?>" required>
				</div>
				<div class="form-group">
					<label>Titik Koordinat</label>
					<input type="text" class="form-control" name="Tikor" value="<?php echo $data1['TIKOR']; ?>" required>
				</div>
				<div class="form-group">
					<label>Email</label>
					<input type="email" class="form-control" name="email" value="<?php echo $data1['EMAIL']; ?>" required>
				</div>
				<div class="form-group">
					<label>No. WhatsApp</label>
					<input type="text" class="form-control" name="nowa" value="<?php echo $data1['NOWA']; ?>" required>
				</div>
				<div class="text-center">
					<button type="submit" name="KIRIM" value="SIMPAN" class="btn btn-qts" id="saveBtn">
						<i class="bi bi-floppy"></i> Simpan Perubahan
					</button>
					<button type="button" class="btn btn-secondary ml-2" onclick="closeModal()">
						<i class="bi bi-x-circle"></i> Tutup
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Bootstrap Icons -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
	
	<script>
		// Handle form submission
		document.querySelector('form').addEventListener('submit', function(e) {
			var saveBtn = document.getElementById('saveBtn');
			
			// Add loading state
			saveBtn.classList.add('btn-loading');
			saveBtn.innerHTML = '<span>Menyimpan...</span>';
			
			// Send message to parent about form submission
			try {
				window.parent.postMessage({
					type: 'form_submitted',
					message: 'Data sedang disimpan...'
				}, '*');
			} catch(e) {
				console.log('Cannot send message to parent:', e);
			}
		});

		// Check if data was saved successfully
		<?php if (isset($success) && $success): ?>
		// Data saved successfully
		setTimeout(function() {
			try {
				// Send success message to parent
				window.parent.postMessage({
					type: 'data_saved',
					message: 'Data berhasil disimpan!'
				}, '*');
			} catch(e) {
				console.log('Cannot send message to parent:', e);
			}
		}, 1000);
		<?php endif; ?>

		// Function to close modal
		function closeModal() {
			try {
				window.parent.postMessage({
					type: 'close_modal',
					reason: 'user_cancel'
				}, '*');
			} catch(e) {
				console.log('Cannot send message to parent:', e);
			}
		}

		// Listen for messages from parent
		window.addEventListener('message', function(e) {
			if (e.data === 'modal_opened') {
				console.log('Modal opened, iframe ready');
			}
		});

		// Add escape key handler
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		// Form validation enhancement
		document.querySelectorAll('.form-control').forEach(function(input) {
			input.addEventListener('blur', function() {
				if (this.value.trim() === '' && this.hasAttribute('required')) {
					this.style.borderColor = '#dc3545';
				} else {
					this.style.borderColor = '#28a745';
				}
			});

			input.addEventListener('input', function() {
				if (this.style.borderColor === 'rgb(220, 53, 69)' && this.value.trim() !== '') {
					this.style.borderColor = '#28a745';
				}
			});
		});
	</script>

</body>

</html>