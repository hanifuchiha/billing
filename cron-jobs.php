<?php
require 'header.php';

// Only allow ADMIN and SUPERADMIN
$user_akses = $_SESSION['akses'] ?? '';
if ($user_akses !== 'ADMIN' && $user_akses !== 'SUPERADMIN') {
    echo "<script>alert('Access Denied'); window.location='index.php';</script>";
    exit;
}
?>

<style>
.cron-job-card {
  border-left: 4px solid #0d6efd;
  transition: box-shadow 0.2s ease;
}

.cron-job-card:hover {
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.cron-job-card.disabled {
  border-left-color: #dc3545;
  opacity: 0.7;
}

.job-status {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.85rem;
  font-weight: 600;
}

.job-status.enabled {
  background-color: #d4edda;
  color: #155724;
}

.job-status.disabled {
  background-color: #f8d7da;
  color: #721c24;
}

.cron-table {
  font-size: 0.9rem;
}

.cron-table td {
  padding: 0.75rem;
  vertical-align: middle;
}

.toggle-switch {
  display: inline-block;
  width: 50px;
  height: 24px;
  background-color: #ccc;
  border-radius: 12px;
  position: relative;
  cursor: pointer;
  transition: background-color 0.3s ease;
}

.toggle-switch.active {
  background-color: #28a745;
}

.toggle-switch::after {
  content: '';
  position: absolute;
  width: 20px;
  height: 20px;
  background-color: white;
  border-radius: 50%;
  top: 2px;
  left: 2px;
  transition: left 0.3s ease;
}

.toggle-switch.active::after {
  left: 28px;
}

.loading-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid #f3f3f3;
  border-top: 2px solid #0d6efd;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin-right: 0.5rem;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0">
            <i class="fas fa-clock me-2"></i>Background Cron Jobs
          </h5>
          <small class="text-light">Manage automatic background processes for OLT and Server data caching</small>
        </div>

        <div class="card-body">
          <!-- Info Alert -->
          <div class="alert alert-info" role="alert">
            <i class="fas fa-info-circle me-2"></i>
            <strong>How it works:</strong> Cron jobs run scripts automatically at set intervals. Enable them to keep your OLT and server data cached and fresh.
          </div>

          <!-- Jobs Table -->
          <div class="table-responsive">
            <table class="table table-hover cron-table">
              <thead class="table-light">
                <tr>
                  <th>Job Name</th>
                  <th>Script Path</th>
                  <th>Interval</th>
                  <th>Status</th>
                  <th>Last Run</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody id="cronJobsTableBody">
                <tr>
                  <td colspan="6" class="text-center py-4">
                    <div class="loading-spinner"></div>
                    Loading cron jobs...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Setup Instructions -->
          <div class="card mt-4 bg-light">
            <div class="card-header bg-secondary text-white">
              <h6 class="mb-0">
                <i class="fas fa-server me-2"></i>Server Crontab Setup
              </h6>
            </div>
            <div class="card-body">
              <p class="text-muted mb-3">To run cron jobs on your server, add these lines to your crontab:</p>
              <div id="cronTabInstructions" class="bg-dark text-light p-3 rounded" style="font-family: 'Courier New', monospace; font-size: 0.9rem; overflow-x: auto;">
                <button type="button" class="btn btn-sm btn-outline-light float-end" onclick="copyCronTab()">
                  <i class="fas fa-copy me-1"></i>Copy
                </button>
                <div id="cronTabContent"></div>
              </div>
              <p class="text-muted small mt-3 mb-0">
                <i class="fas fa-terminal me-1"></i>
                Run <code>crontab -e</code> on your server and paste the lines above.
              </p>
            </div>
          </div>

          <!-- Manual Run Section -->
          <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
              <h6 class="mb-0">
                <i class="fas fa-play-circle me-2"></i>Manual Trigger
              </h6>
            </div>
            <div class="card-body">
              <p class="text-muted mb-3">Run a cron job manually (useful for testing):</p>
              <button type="button" class="btn btn-sm btn-success" onclick="runCronJob('olt_cache')">
                <i class="fas fa-play me-1"></i>Run OLT Cache Now
              </button>
              <button type="button" class="btn btn-sm btn-success" onclick="runCronJob('server_ping')">
                <i class="fas fa-play me-1"></i>Run Server Ping Now
              </button>
              <div id="manualRunMessage" class="mt-3" style="display:none;"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Load cron jobs
function loadCronJobs() {
  fetch('getdata/cron-manager.php?action=list', {
    credentials: 'same-origin'
  })
  .then(r => {
    if (!r.ok) throw new Error('Failed to load cron jobs');
    return r.json();
  })
  .then(data => {
    if (data.success) {
      renderCronJobsTable(data.jobs);
    } else {
      showError(data.error);
    }
  })
  .catch(err => showError(err.message));
}

// Render cron jobs table
function renderCronJobsTable(jobs) {
  const tbody = document.getElementById('cronJobsTableBody');
  
  if (jobs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No cron jobs configured</td></tr>';
    return;
  }
  
  tbody.innerHTML = jobs.map(job => `
    <tr class="cron-job-card ${!job.enabled ? 'disabled' : ''}">
      <td>
        <strong>${escapeHtml(job.job_name)}</strong>
        <br>
        <small class="text-muted">${getJobDescription(job.job_name)}</small>
      </td>
      <td>
        <code class="text-xs">${escapeHtml(job.script_path)}</code>
      </td>
      <td>
        <span class="badge bg-info">${job.interval_minutes} min</span>
      </td>
      <td>
        <span class="job-status ${job.enabled ? 'enabled' : 'disabled'}">
          ${job.enabled ? '<i class="fas fa-check-circle me-1"></i>Enabled' : '<i class="fas fa-times-circle me-1"></i>Disabled'}
        </span>
      </td>
      <td>
        ${job.last_run ? new Date(job.last_run).toLocaleString() : '<span class="text-muted">Never</span>'}
      </td>
      <td class="text-center">
        <button class="btn btn-sm ${job.enabled ? 'btn-danger' : 'btn-success'}" onclick="toggleCronJob('${job.job_name}')">
          ${job.enabled ? '<i class="fas fa-power-off"></i> Off' : '<i class="fas fa-power-on"></i> On'}
        </button>
      </td>
    </tr>
  `).join('');
}

// Toggle cron job
function toggleCronJob(jobName) {
  fetch('getdata/cron-manager.php?action=toggle&job=' + encodeURIComponent(jobName), {
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showSuccess(data.message);
      setTimeout(loadCronJobs, 500);
    } else {
      showError(data.error);
    }
  })
  .catch(err => showError(err.message));
}

// Run cron job manually
function runCronJob(jobName) {
  const btn = event.target.closest('button');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<div class="loading-spinner"></div>Running...';
  
  fetch('getdata/cron-manager.php?action=run&job=' + encodeURIComponent(jobName), {
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    
    if (data.success) {
      showSuccess(data.message);
      setTimeout(loadCronJobs, 1000);
    } else {
      showError(data.error);
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    showError(err.message);
  });
}

// Load crontab instructions
function loadCrontabInstructions() {
  fetch('getdata/cron-manager.php?action=setup_crontab', {
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const content = data.crontab_lines.map(line => `${line}\n`).join('');
      document.getElementById('cronTabContent').textContent = content;
    }
  })
  .catch(err => console.error(err));
}

// Copy crontab to clipboard
function copyCronTab() {
  const content = document.getElementById('cronTabContent').textContent;
  navigator.clipboard.writeText(content).then(() => {
    showSuccess('Crontab copied to clipboard!');
  }).catch(err => showError('Failed to copy: ' + err.message));
}

// Utility functions
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function getJobDescription(jobName) {
  const descriptions = {
    'olt_cache': 'Updates OLT data cache for instant display',
    'server_ping': 'Pings all servers to check connectivity status'
  };
  return descriptions[jobName] || jobName;
}

function showSuccess(msg) {
  const alert = document.createElement('div');
  alert.className = 'alert alert-success alert-dismissible fade show';
  alert.innerHTML = `
    <i class="fas fa-check-circle me-2"></i>${msg}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  `;
  document.querySelector('.card-body').prepend(alert);
  setTimeout(() => alert.remove(), 5000);
}

function showError(msg) {
  const alert = document.createElement('div');
  alert.className = 'alert alert-danger alert-dismissible fade show';
  alert.innerHTML = `
    <i class="fas fa-exclamation-circle me-2"></i>${msg}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  `;
  document.querySelector('.card-body').prepend(alert);
  setTimeout(() => alert.remove(), 5000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  loadCronJobs();
  loadCrontabInstructions();
  
  // Reload every 30 seconds
  setInterval(loadCronJobs, 30000);
});
</script>

<?php require 'footer.php'; ?>
