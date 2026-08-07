<?php
/**
 * ACS Helper Class
 * Handles Docker management, GenieACS API communication, and user permissions
 */

class ACSHelper {
    private $conn;
    private $user_id;
    private $user_role;
    private $backup_repo = 'https://github.com/alijayanet/virtualparameter.git';
    private $backup_dir;
    private $last_mongo_wait_error = '';

    public function __construct($conn, $user_id, $user_role) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->user_role = $user_role;
        $this->backup_dir = dirname(__FILE__) . '/virtualparameter';
    }

    /**
     * Check if user is ADMIN
     */
    public function isAdmin() {
        return $this->user_role === 'ADMIN';
    }

    /**
     * Ensure database tables exist
     */
    public function ensureDatabase() {
        require_once 'acs_install_db.php';
        $check = checkACSTablesExist($this->conn);

        $result = ['success' => true, 'message' => 'Database already initialized'];
        if (!$check['all_exist']) {
            $result = installACSDatabase($this->conn);
        }

        // Column/index migrations must run even when all tables already exist.
        migrateACSServersColumns($this->conn);

        return $result;
    }

    /**
     * Get user's assigned server
     */
    public function getUserServer() {
        if ($this->isAdmin()) {
            return null; // ADMIN can see all servers
        }

        $sql = "SELECT s.* FROM acs_servers s
                INNER JOIN acs_user_server_assignment a ON s.id = a.server_id
                WHERE a.user_id = " . intval($this->user_id) . "
                LIMIT 1";
        
        $result = mysqli_query($this->conn, $sql);
        return mysqli_fetch_assoc($result);
    }

    /**
     * Get all servers for all users.
     */
    public function getServers() {
        $sql = "SELECT s.*, u.USERNAME as owner_username
                FROM acs_servers s
                LEFT JOIN user u ON s.owner_id = u.id
                ORDER BY s.created_at DESC";
        
        $result = mysqli_query($this->conn, $sql);
        $servers = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $servers[] = $row;
        }
        return $servers;
    }

    /**
     * Get server by ID.
     */
    public function getServer($server_id) {
        $sql = "SELECT s.*, u.USERNAME as owner_username
                FROM acs_servers s
                LEFT JOIN user u ON s.owner_id = u.id
                WHERE s.id = " . intval($server_id);
        
        $result = mysqli_query($this->conn, $sql);
        return mysqli_fetch_assoc($result);
    }

    /**
     * Check if port is available
     */
    public function isPortAvailable($port) {
        // Only Docker-provisioned servers actually reserve a host port on this
        // machine; external servers store their remote UI port in this same
        // column but don't occupy anything locally, so they're excluded here.
        $sql = "SELECT id FROM acs_servers WHERE port = " . intval($port) . " AND is_external = 0";
        $result = mysqli_query($this->conn, $sql);
        return mysqli_num_rows($result) === 0;
    }

    /**
     * Normalize domain input to host only (no scheme/path/port).
     */
    private function normalizeDomain($domain) {
        $domain = trim((string)$domain);
        if ($domain === '') {
            return '';
        }

        if (stripos($domain, 'http://') !== 0 && stripos($domain, 'https://') !== 0) {
            $domain = 'http://' . $domain;
        }

        $parts = @parse_url($domain);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }

        return strtolower(trim($parts['host']));
    }

    /**
     * Each ACS server reserves 4 host ports inside 5000-5999.
     * ui=port, cwmp=port+1, nbi=port+2, fs=port+3
     */
    private function getPortBundle($port) {
        $base = intval($port);
        return [
            'ui' => $base,
            'cwmp' => $base + 1,
            'nbi' => $base + 2,
            'fs' => $base + 3,
        ];
    }

    /**
     * Check if local host port is already in use by any process.
     */
    private function isHostPortInUse($port) {
        $connection = @fsockopen('127.0.0.1', intval($port), $errno, $errstr, 0.2);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Return docker container names that publish a given host port.
     */
    private function getDockerPortOwners($port) {
        $owners = [];
        $output = [];
        $code = -1;

        @exec("docker ps --filter publish=" . intval($port) . " --format \"{{.Names}}\" 2>&1", $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                $name = trim($line);
                if ($name !== '') {
                    $owners[] = $name;
                }
            }
        }

        return array_values(array_unique($owners));
    }

    /**
     * Validate full 4-port bundle availability against DB bundles and live host ports.
     */
    private function isPortBundleAvailable($port) {
        $new_bundle = $this->getPortBundle($port);

        // Must stay in requested range 5000-5999
        foreach ($new_bundle as $p) {
            if ($p < 5000 || $p > 5999) {
                return [
                    'available' => false,
                    'message' => 'Port bundle harus berada di range 5000-5999. Gunakan base port 5000-5996.'
                ];
            }
        }

        // Check overlap with existing Docker-provisioned bundles (external servers
        // run on a different host and don't reserve any port on this machine)
        $result = mysqli_query($this->conn, "SELECT id, port FROM acs_servers WHERE is_external = 0");
        while ($row = mysqli_fetch_assoc($result)) {
            $existing_bundle = $this->getPortBundle(intval($row['port']));
            $overlap = array_intersect(array_values($new_bundle), array_values($existing_bundle));
            if (!empty($overlap)) {
                return [
                    'available' => false,
                    'message' => 'Bentrok dengan server ACS lain pada port: ' . implode(', ', $overlap)
                ];
            }
        }

        // Check live host ports
        foreach ($new_bundle as $name => $p) {
            if ($this->isHostPortInUse($p)) {
                $owners = $this->getDockerPortOwners($p);
                $owner_text = '';
                if (!empty($owners)) {
                    $owner_text = ' | dipakai container: ' . implode(', ', $owners);
                }
                return [
                    'available' => false,
                    'message' => 'Port host sedang dipakai: ' . $p . ' (' . strtoupper($name) . ')' . $owner_text
                ];
            }
        }

        return ['available' => true, 'message' => 'OK'];
    }

    /**
     * Find the first free base port in 5000-5996 for a 4-port bundle.
     */
    private function findAvailableBasePort() {
        for ($base = 5000; $base <= 5996; $base++) {
            if (!$this->isPortAvailable($base)) {
                continue;
            }

            $bundle_check = $this->isPortBundleAvailable($base);
            if ($bundle_check['available']) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Public helper for UI to show next available automatic base port.
     */
    public function getNextAvailableBasePort() {
        return $this->findAvailableBasePort();
    }

    /**
     * Ensure backup repository is ready
     */
    private function ensureBackupRepoReady() {
        // Check if Git is available
        $git_check = [];
        $git_check_code = -1;
        @exec("git --version 2>&1", $git_check, $git_check_code);
        
        if ($git_check_code !== 0) {
            return [
                'success' => false,
                'message' => 'Git tidak terinstall. Silakan install Git terlebih dahulu.'
            ];
        }

        // Clone if not exists
        if (!is_dir($this->backup_dir)) {
            $output = [];
            $return_var = -1;
            @exec("git clone " . escapeshellarg($this->backup_repo) . " " . escapeshellarg($this->backup_dir) . " 2>&1", $output, $return_var);
            
            if ($return_var !== 0) {
                return [
                    'success' => false,
                    'message' => 'Gagal clone repository: ' . implode("\n", $output)
                ];
            }
        } else {
            // Keep existing repo fresh
            $output = [];
            $return_var = 0;
            @exec("git -C " . escapeshellarg($this->backup_dir) . " pull --ff-only 2>&1", $output, $return_var);
        }

        // Validate that directory has BSON files
        $bson_files = glob($this->backup_dir . '/*.bson');
        if (empty($bson_files)) {
            return [
                'success' => false,
                'message' => 'Tidak ada file BSON ditemukan di repository virtualparameter'
            ];
        }

        return ['success' => true];
    }

    /**
     * Wait until MongoDB inside container is ready to accept commands.
     */
    private function waitForMongoReady($mongo_container, $max_seconds = 60) {
        $start = time();
        $last_output = [];
        $last_code = -1;
        while ((time() - $start) < $max_seconds) {
            $output = [];
            $code = -1;

            // mongo shell exists on mongo:4.4 image
            @exec("docker exec " . escapeshellarg($mongo_container) . " mongo --quiet --eval \"db.runCommand({ ping: 1 }).ok\" 2>&1", $output, $code);
            $last_output = $output;
            $last_code = $code;
            if ($code === 0) {
                $joined = trim(implode("\n", $output));
                if ($joined === '1' || strpos($joined, '1') !== false) {
                    return true;
                }
            }

            sleep(2);
        }

        $this->last_mongo_wait_error = 'exit=' . $last_code . ' output=' . implode(" | ", $last_output);
        return false;
    }

    /**
     * Wait until GenieACS API port is responsive (health check)
     * Uses NBI port (base_port + 2) for API access
     */
    private function waitForACSAPIReady($domain, $port, $username, $password, $max_seconds = 120) {
        // Use NBI port for API access
        $nbi_port = intval($port) + 2;
        $url = "http://{$domain}:{$nbi_port}/devices";
        $start = time();
        
        while ((time() - $start) < $max_seconds) {
            $ch = @curl_init($url);
            if (!$ch) {
                sleep(2);
                continue;
            }
            
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            @curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            @curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            
            $response = @curl_exec($ch);
            $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            
            // Accept 200 (success), 401/403 (auth issues - but API responding), or 400 (bad request - API responding)
            if ($http_code >= 200 && $http_code < 500) {
                return [
                    'success' => true,
                    'http_code' => $http_code,
                    'message' => "API responsive (HTTP $http_code)"
                ];
            }
            
            sleep(2);
        }
        
        return [
            'success' => false,
            'http_code' => 0,
            'message' => 'ACS API tidak responsif setelah ' . $max_seconds . ' detik'
        ];
    }

    /**
     * Stop/remove any Docker resources for a server's compose directory and
     * wipe the directory itself. Shared by teardownFailedServer() (cleanup
     * after a failed attempt) and cleanupLeftoverServer() (cleanup before a
     * new attempt starts).
     */
    private function wipeComposeDir($compose_dir) {
        if (!$compose_dir || !is_dir($compose_dir)) {
            return;
        }

        $cleanup_output = [];
        $cleanup_code = -1;
        $current_dir = getcwd();
        chdir($compose_dir);
        @exec("docker compose down -v 2>&1", $cleanup_output, $cleanup_code);
        chdir($current_dir);

        // mongo's data files are owned by the container's internal
        // "mongodb" uid, not the web server user, so plain PHP
        // unlink()/rmdir() can't remove them. Wipe via a throwaway
        // root container instead, which can touch any uid on the
        // bind-mounted volume.
        if (is_dir($compose_dir . '/mongo')) {
            @exec("docker run --rm -v " . escapeshellarg($compose_dir) . ":/target busybox sh -c "
                . escapeshellarg('rm -rf /target/mongo') . " 2>&1");
        }

        $this->deleteDirectory($compose_dir);
    }

    /**
     * Fully undo a failed createServer() attempt: stop/remove any Docker
     * resources, wipe the compose directory, and remove the DB row. Nothing
     * about a failed attempt should remain — no half-built container left
     * running, no orphaned 'ERROR' row in acs_servers.
     */
    private function teardownFailedServer($server_id, $compose_dir) {
        $this->wipeComposeDir($compose_dir);
        if ($server_id) {
            mysqli_query($this->conn, "DELETE FROM acs_user_server_assignment WHERE server_id = " . intval($server_id));
            mysqli_query($this->conn, "DELETE FROM acs_servers WHERE id = " . intval($server_id));
        }
    }

    /**
     * Wipe anything left behind for this exact port/container name before a
     * new createServer() attempt starts building on top of it — a leftover
     * container or data directory (from a crash, a killed request, or an
     * attempt made before teardownFailedServer() existed) is what makes a
     * brand new mongo collide with old locked/corrupt data and crash-loop.
     */
    private function cleanupLeftoverServer($port, $container_name) {
        $compose_dir = dirname(__FILE__) . '/acs_containers/' . $container_name;
        $this->wipeComposeDir($compose_dir);

        // Belt-and-braces: force-remove by the exact container names this
        // port would produce, in case they exist without a matching compose
        // directory (e.g. directory already removed by hand, or the
        // container was created by a version of this code before the
        // teardown/cleanup helpers existed).
        @exec("docker rm -f " . escapeshellarg('genieacs-mongo-' . $port) . " 2>&1");
        @exec("docker rm -f " . escapeshellarg('genieacs-' . $port) . " 2>&1");

        // Any stale acs_servers row for this exact port (from before this
        // cleanup existed) would also make isPortAvailable() skip the port
        // forever otherwise.
        mysqli_query($this->conn, "DELETE FROM acs_user_server_assignment WHERE server_id IN (SELECT id FROM acs_servers WHERE port = " . intval($port) . " AND is_external = 0)");
        mysqli_query($this->conn, "DELETE FROM acs_servers WHERE port = " . intval($port) . " AND is_external = 0");
    }

    /**
     * Create new ACS server with Docker container
     */
    public function createServer($nama_server, $domain, $port = null, $username_acs = '', $password_acs = '') {
        if (!$this->isAdmin()) {
            return ['success' => false, 'message' => 'Only ADMIN can create servers'];
        }

        // Step-by-step progress log surfaced to the UI as a checklist, so a
        // failure at any stage shows exactly how far it got instead of just
        // a final error blob.
        $steps = [];

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return ['success' => false, 'message' => 'Domain server tidak valid'];
        }

        // Validate username and password are provided
        if (empty($username_acs) || empty($password_acs)) {
            return ['success' => false, 'message' => 'Username dan password ACS harus diisi'];
        }

        // Auto select base port when not provided by UI
        if ($port === null || intval($port) <= 0) {
            $auto_port = $this->findAvailableBasePort();
            if ($auto_port === null) {
                return ['success' => false, 'message' => 'Tidak ada port tersedia di range 5000-5999'];
            }
            $port = $auto_port;
        }
        $port = intval($port);

        // Validate base port range for 4-port bundle in 5000-5999
        if ($port < 5000 || $port > 5996) {
            return ['success' => false, 'message' => 'Base port harus antara 5000-5996'];
        }

        // Check ACS database UI port availability
        if (!$this->isPortAvailable($port)) {
            return ['success' => false, 'message' => 'Base port ' . $port . ' sudah digunakan'];
        }

        // Check full bundle availability
        $bundle_check = $this->isPortBundleAvailable($port);
        if (!$bundle_check['available']) {
            return ['success' => false, 'message' => $bundle_check['message']];
        }

        // Check Docker
        $docker_check = [];
        $docker_check_code = -1;
        @exec("docker --version 2>&1", $docker_check, $docker_check_code);

        if ($docker_check_code !== 0) {
            $steps[] = ['label' => 'Docker tersedia', 'ok' => false, 'detail' => implode(" | ", $docker_check)];
            return [
                'success' => false,
                'message' => 'Docker tidak terinstall, tidak dapat diakses oleh PHP (cek exec() disabled / izin akses docker.sock untuk user web server), atau tidak ditemukan di PATH: '
                    . implode(" | ", $docker_check),
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'Docker tersedia', 'ok' => true];

        // Prepare backup config
        $backup_result = $this->ensureBackupRepoReady();
        if (!$backup_result['success']) {
            $steps[] = ['label' => 'Config virtual parameter siap (clone/pull repo)', 'ok' => false, 'detail' => $backup_result['message']];
            $backup_result['steps'] = $steps;
            return $backup_result;
        }
        $steps[] = ['label' => 'Config virtual parameter siap (clone/pull repo)', 'ok' => true];

        $container_name = 'acs_' . $port;

        // Wipe any leftover container/data/DB-row for this exact port before
        // building a new one — a container/directory surviving from an
        // earlier attempt (crash, killed request, pre-fix bug) is exactly
        // what makes a fresh mongo collide with old locked/corrupt data and
        // crash-loop again.
        $this->cleanupLeftoverServer($port, $container_name);
        $steps[] = ['label' => 'Sisa container/data lama dibersihkan', 'ok' => true];

        // Insert server record with CREATING status
        $sql = "INSERT INTO acs_servers (nama_server, domain, port, username_acs, password_acs, container_name, owner_id, status)
                VALUES (
                    '" . mysqli_real_escape_string($this->conn, $nama_server) . "',
                    '" . mysqli_real_escape_string($this->conn, $domain) . "',
                    " . intval($port) . ",
                    '" . mysqli_real_escape_string($this->conn, $username_acs) . "',
                    '" . mysqli_real_escape_string($this->conn, $password_acs) . "',
                    '" . mysqli_real_escape_string($this->conn, $container_name) . "',
                    " . intval($this->user_id) . ",
                    'CREATING'
                )";
        
        if (!mysqli_query($this->conn, $sql)) {
            return ['success' => false, 'message' => 'Database error: ' . mysqli_error($this->conn)];
        }

        $server_id = mysqli_insert_id($this->conn);

        // Create docker-compose directory
        $compose_dir = dirname(__FILE__) . '/acs_containers/' . $container_name;
        if (!is_dir($compose_dir)) {
            mkdir($compose_dir, 0755, true);
        }

        // Create docker-compose.yml
        $compose_content = $this->generateDockerCompose($port);
        file_put_contents($compose_dir . '/docker-compose.yml', $compose_content);

        // Start MongoDB only first. GenieACS auto-creates its own internal
        // collections (locks, tasks, cache, faults, ...) the instant it can
        // reach Mongo, which races with the mongorestore --drop below and
        // randomly fails one of those collections with NamespaceExists.
        // Keeping genieacs stopped until after the restore avoids the race
        // regardless of which collection GenieACS happens to touch first.
        $output = [];
        $return_var = -1;
        $current_dir = getcwd();
        chdir($compose_dir);
        @exec("docker compose up -d mongo 2>&1", $output, $return_var);
        chdir($current_dir);

        if ($return_var !== 0) {
            $steps[] = ['label' => 'Container MongoDB dibuat & berjalan', 'ok' => false, 'detail' => implode("\n", $output)];
            $this->teardownFailedServer($server_id, $compose_dir);
            return [
                'success' => false,
                'message' => 'Gagal memulai container: ' . implode("\n", $output),
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'Container MongoDB dibuat & berjalan', 'ok' => true];

        // Wait for MongoDB to be ready
        $mongo_container = 'genieacs-mongo-' . $port;
        if (!$this->waitForMongoReady($mongo_container, 60)) {
            // Grab the container's own crash log before it's torn down —
            // "docker exec ... is restarting" only says mongod keeps dying,
            // not why. The real reason (OOM, WiredTiger error, bad data
            // dir, ...) is in `docker logs`.
            $mongo_logs = [];
            @exec("docker logs --tail 40 " . escapeshellarg($mongo_container) . " 2>&1", $mongo_logs);

            $detail = $this->last_mongo_wait_error
                . (empty($mongo_logs) ? '' : "\nLog container:\n" . implode("\n", $mongo_logs));
            $steps[] = ['label' => 'MongoDB siap menerima koneksi', 'ok' => false, 'detail' => $detail];

            // Tear down the broken container instead of leaving it behind:
            // with "restart: always" a stuck mongo would otherwise keep
            // restart-looping forever and permanently squat this port for
            // every future attempt.
            $this->teardownFailedServer($server_id, $compose_dir);

            return [
                'success' => false,
                'message' => 'MongoDB belum siap menerima restore pada container ' . $mongo_container
                    . '. Detail: ' . $detail,
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'MongoDB siap menerima koneksi', 'ok' => true];

        // Import backup data (genieacs container is not started yet, so nothing
        // else is writing to this database while the restore runs)
        $import_result = $this->importBackupData($container_name);
        if (!$import_result['success']) {
            $detail_lines = [];
            if (isset($import_result['output']) && is_array($import_result['output'])) {
                $detail_lines = array_slice($import_result['output'], -8);
            }
            $detail = $import_result['message'] . (empty($detail_lines) ? '' : "\nDetail: " . implode(" | ", $detail_lines));
            $steps[] = ['label' => 'Restore virtual parameter ke MongoDB', 'ok' => false, 'detail' => $detail];

            $this->teardownFailedServer($server_id, $compose_dir);

            return [
                'success' => false,
                'message' => 'Restore backup gagal: ' . $detail,
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'Restore virtual parameter ke MongoDB', 'ok' => true];

        // Now start GenieACS itself, after the restore has finished
        $genieacs_output = [];
        $genieacs_return_var = -1;
        $current_dir = getcwd();
        chdir($compose_dir);
        @exec("docker compose up -d genieacs 2>&1", $genieacs_output, $genieacs_return_var);
        chdir($current_dir);

        if ($genieacs_return_var !== 0) {
            $steps[] = ['label' => 'Container GenieACS dibuat & berjalan', 'ok' => false, 'detail' => implode("\n", $genieacs_output)];

            $this->teardownFailedServer($server_id, $compose_dir);

            return [
                'success' => false,
                'message' => 'Restore backup berhasil tetapi gagal memulai container GenieACS: ' . implode("\n", $genieacs_output),
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'Container GenieACS dibuat & berjalan', 'ok' => true];

        // Wait for ACS API to be ready
        $api_check = $this->waitForACSAPIReady($domain, $port, $username_acs, $password_acs, 120);
        if (!$api_check['success']) {
            $steps[] = ['label' => 'GenieACS API merespons', 'ok' => false, 'detail' => $api_check['message']];

            $this->teardownFailedServer($server_id, $compose_dir);

            return [
                'success' => false,
                'message' => 'Server container running tetapi API tidak responsif: ' . $api_check['message'],
                'steps' => $steps
            ];
        }
        $steps[] = ['label' => 'GenieACS API merespons', 'ok' => true];

        // Update status to RUNNING
        mysqli_query($this->conn, "UPDATE acs_servers SET status = 'RUNNING' WHERE id = $server_id");
        $steps[] = ['label' => 'Server diaktifkan (status RUNNING)', 'ok' => true];

        // Assign server to ADMIN user
        $this->assignServerToUser($server_id, $this->user_id);

        // Create port forwarding in MikroTik
        $forwarding_result = $this->createMikroTikPortForwarding($port, $nama_server);
        if (!$forwarding_result['success']) {
            // Log warning but don't fail - server is running
            error_log('ACS Port Forwarding Warning: ' . $forwarding_result['message']);
        }
        $steps[] = ['label' => 'Port forwarding MikroTik', 'ok' => !empty($forwarding_result['success']), 'detail' => $forwarding_result['message'] ?? ''];

        return [
            'success' => true,
            'message' => 'Server berhasil dibuat',
            'server_id' => $server_id,
            'base_port' => $port,
            'container_name' => $container_name,
            'import_result' => $import_result,
            'forwarding_result' => $forwarding_result,
            'api_check' => $api_check,
            'steps' => $steps
        ];
    }

    /**
     * Register an already-running external GenieACS server (no Docker container
     * is created; ports are stored as given since external installs don't
     * necessarily follow the sequential base/base+1/base+2/base+3 bundle scheme).
     */
    public function createExternalServer($nama_server, $domain, $ui_port, $cwmp_port, $nbi_port, $fs_port, $username_acs, $password_acs, $allowed_ips = '') {
        if (!$this->isAdmin()) {
            return ['success' => false, 'message' => 'Only ADMIN can create servers'];
        }

        $nama_server = trim((string)$nama_server);
        if ($nama_server === '') {
            return ['success' => false, 'message' => 'Nama server harus diisi'];
        }

        $domain = $this->normalizeDomain($domain);
        if ($domain === '') {
            return ['success' => false, 'message' => 'Domain/IP server tidak valid'];
        }

        if (empty($username_acs) || empty($password_acs)) {
            return ['success' => false, 'message' => 'Username dan password ACS harus diisi'];
        }

        $ports = [
            'ui' => intval($ui_port),
            'cwmp' => intval($cwmp_port),
            'nbi' => intval($nbi_port),
            'fs' => intval($fs_port),
        ];
        foreach ($ports as $label => $p) {
            if ($p < 1 || $p > 65535) {
                return ['success' => false, 'message' => 'Port ' . strtoupper($label) . ' tidak valid (harus 1-65535)'];
            }
        }

        // Prevent registering the exact same external endpoint twice
        $dup_sql = "SELECT id FROM acs_servers WHERE is_external = 1 AND domain = '"
            . mysqli_real_escape_string($this->conn, $domain) . "' AND nbi_port = " . $ports['nbi'];
        $dup_result = mysqli_query($this->conn, $dup_sql);
        if ($dup_result && mysqli_num_rows($dup_result) > 0) {
            return ['success' => false, 'message' => 'Server dengan domain dan NBI port ini sudah terdaftar'];
        }

        $container_name = 'external_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $domain) . '_' . $ports['nbi'];

        $allowed_ips = trim((string)$allowed_ips);

        $sql = "INSERT INTO acs_servers
                    (nama_server, domain, port, username_acs, password_acs, container_name, owner_id, status, is_external, ui_port, cwmp_port, nbi_port, fs_port, allowed_ips)
                VALUES (
                    '" . mysqli_real_escape_string($this->conn, $nama_server) . "',
                    '" . mysqli_real_escape_string($this->conn, $domain) . "',
                    " . $ports['ui'] . ",
                    '" . mysqli_real_escape_string($this->conn, $username_acs) . "',
                    '" . mysqli_real_escape_string($this->conn, $password_acs) . "',
                    '" . mysqli_real_escape_string($this->conn, $container_name) . "',
                    " . intval($this->user_id) . ",
                    'RUNNING',
                    1,
                    " . $ports['ui'] . ",
                    " . $ports['cwmp'] . ",
                    " . $ports['nbi'] . ",
                    " . $ports['fs'] . ",
                    " . ($allowed_ips !== '' ? "'" . mysqli_real_escape_string($this->conn, $allowed_ips) . "'" : "NULL") . "
                )";

        if (!mysqli_query($this->conn, $sql)) {
            return ['success' => false, 'message' => 'Database error: ' . mysqli_error($this->conn)];
        }

        $server_id = mysqli_insert_id($this->conn);
        $this->assignServerToUser($server_id, $this->user_id);

        // Best-effort reachability check; server stays registered even if this fails
        // (e.g. NBI only reachable from a local network, not from this app server).
        $api_response = $this->callGenieACSAPI("http://{$domain}:{$ports['nbi']}", '/devices', $username_acs, $password_acs);
        $api_check = $api_response !== false
            ? ['success' => true, 'message' => 'NBI merespons dan mengembalikan data devices']
            : ['success' => false, 'message' => 'Tidak bisa menghubungi NBI di ' . $domain . ':' . $ports['nbi']];

        return [
            'success' => true,
            'message' => 'Server ACS eksternal berhasil didaftarkan',
            'server_id' => $server_id,
            'container_name' => $container_name,
            'api_check' => $api_check
        ];
    }

    /**
     * Create port forwarding rule in MikroTik for ACS UI port
     */
    private function createMikroTikPortForwarding($port, $nama_server) {
        // Load config
        $config_file = dirname(__FILE__) . '/config.json';
        if (!file_exists($config_file)) {
            return ['success' => false, 'message' => 'Config file tidak ditemukan'];
        }

        $config = json_decode(file_get_contents($config_file), true);
        if (!$config) {
            return ['success' => false, 'message' => 'Config file tidak valid'];
        }

        // Check if required config exists
        if (empty($config['router_ip']) || empty($config['router_user']) || empty($config['router_pass']) || empty($config['ippublicportapi'])) {
            return ['success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap'];
        }

        // Load RouterOS API class
        if (!file_exists(dirname(__FILE__) . '/routeros_api.class.php')) {
            return ['success' => false, 'message' => 'RouterOS API class tidak ditemukan'];
        }

        require_once dirname(__FILE__) . '/routeros_api.class.php';

        $API = new RouterosAPI();
        $router_ip = $config['router_ip'];
        $router_user = $config['router_user'];
        $router_pass = $config['router_pass'];
        $router_port = $config['ippublicportapi'];

        // Connect to MikroTik
        if (!$API->connect($router_ip . ":" . $router_port, $router_user, $router_pass)) {
            return ['success' => false, 'message' => 'Gagal terhubung ke MikroTik API'];
        }

        try {
            $ippublic = isset($config['ippublic']) ? $config['ippublic'] : '';
            $lokalwebserver = isset($config['webiplocal']) ? $config['webiplocal'] : 'localhost';
            
            // Get port bundle
            $bundle = $this->getPortBundle($port);
            $forwarded_ports = [];
            $port_names = [
                'ui' => 'UI',
                'cwmp' => 'CWMP',
                'nbi' => 'NBI',
                'fs' => 'FS'
            ];

            // Create NAT rule for each port in bundle. The RouterOS API reports
            // per-command failures (e.g. duplicate rule) as a '!trap' reply, not
            // a PHP exception, so each response must be checked explicitly —
            // otherwise a failed rule for one port is silently ignored and the
            // whole bundle gets reported as forwarded when it wasn't.
            $failed_ports = [];
            foreach ($bundle as $type => $port_num) {
                $comment = "ACS {$port_names[$type]} forward port $port_num | $nama_server";
                $API->write('/ip/firewall/nat/add', false);
                $API->write('=chain=dstnat', false);

                if (!empty($ippublic)) {
                    $API->write('=dst-address=' . $ippublic, false);
                }

                $API->write('=protocol=tcp', false);
                $API->write('=dst-port=' . $port_num, false);
                $API->write('=action=dst-nat', false);
                $API->write('=to-addresses=' . $lokalwebserver, false);
                $API->write('=to-ports=' . $port_num, false);
                $API->write('=comment=' . $comment);

                $response = $API->read();
                if (isset($response['!trap'])) {
                    $trap_message = '';
                    foreach ($response['!trap'] as $trap) {
                        if (isset($trap['message'])) {
                            $trap_message = $trap['message'];
                            break;
                        }
                    }
                    $failed_ports[] = $port_num . ' (' . $port_names[$type] . ')'
                        . ($trap_message !== '' ? ': ' . $trap_message : '');
                } else {
                    $forwarded_ports[] = $port_num . ' (' . $port_names[$type] . ')';
                }
            }

            $API->disconnect();

            if (!empty($failed_ports)) {
                return [
                    'success' => false,
                    'message' => 'Sebagian port gagal di-forward: ' . implode(', ', $failed_ports)
                        . (empty($forwarded_ports) ? '' : ' | Berhasil: ' . implode(', ', $forwarded_ports)),
                    'ports' => $bundle,
                    'forwarded_ports' => $forwarded_ports,
                    'failed_ports' => $failed_ports
                ];
            }

            return [
                'success' => true,
                'message' => 'Port forwarding berhasil dibuat di MikroTik untuk: ' . implode(', ', $forwarded_ports),
                'ports' => $bundle
            ];
        } catch (Exception $e) {
            $API->disconnect();
            return [
                'success' => false,
                'message' => 'Error MikroTik: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Re-run MikroTik NAT rule creation for an existing Docker-provisioned
     * server, without touching its container/database. For fixing servers
     * whose forwarding was left incomplete by the earlier per-port-failure
     * bug in createMikroTikPortForwarding().
     */
    public function repairPortForwarding($server_id) {
        if (!$this->isAdmin()) {
            return ['success' => false, 'message' => 'Only ADMIN can repair port forwarding'];
        }

        $server = $this->getServer($server_id);
        if (!$server) {
            return ['success' => false, 'message' => 'Server tidak ditemukan'];
        }

        if (!empty($server['is_external'])) {
            return ['success' => false, 'message' => 'Server eksternal tidak memakai port forwarding otomatis'];
        }

        return $this->createMikroTikPortForwarding((int)$server['port'], $server['nama_server']);
    }

    /**
     * Delete port forwarding rules from MikroTik for ACS server
     */
    private function deleteMikroTikPortForwarding($port, $nama_server) {
        // Load config
        $config_file = dirname(__FILE__) . '/config.json';
        if (!file_exists($config_file)) {
            return ['success' => false, 'message' => 'Config file tidak ditemukan'];
        }

        $config = json_decode(file_get_contents($config_file), true);
        if (!$config) {
            return ['success' => false, 'message' => 'Config file tidak valid'];
        }

        // Check if required config exists
        if (empty($config['router_ip']) || empty($config['router_user']) || empty($config['router_pass']) || empty($config['ippublicportapi'])) {
            return ['success' => false, 'message' => 'Konfigurasi MikroTik belum lengkap'];
        }

        // Load RouterOS API class
        if (!file_exists(dirname(__FILE__) . '/routeros_api.class.php')) {
            return ['success' => false, 'message' => 'RouterOS API class tidak ditemukan'];
        }

        require_once dirname(__FILE__) . '/routeros_api.class.php';

        $API = new RouterosAPI();
        $router_ip = $config['router_ip'];
        $router_user = $config['router_user'];
        $router_pass = $config['router_pass'];
        $router_port = $config['ippublicportapi'];

        // Connect to MikroTik
        if (!$API->connect($router_ip . ":" . $router_port, $router_user, $router_pass)) {
            return ['success' => false, 'message' => 'Gagal terhubung ke MikroTik API'];
        }

        try {
            // Get port bundle
            $bundle = $this->getPortBundle($port);
            $deleted_rules = [];

            // Get all NAT rules
            $API->write('/ip/firewall/nat/print', false);
            $API->write('?chain=dstnat');
            $natRules = $API->read();

            // Find and delete rules for this ACS server ports
            foreach ($natRules as $rule) {
                if (!isset($rule['.id']) || !isset($rule['dst-port'])) {
                    continue;
                }

                $rulePort = intval($rule['dst-port']);
                $comment = isset($rule['comment']) ? $rule['comment'] : '';

                // Check if this rule belongs to our ACS server (by port or comment)
                if (in_array($rulePort, array_values($bundle)) || 
                    ($comment !== '' && strpos($comment, $nama_server) !== false && strpos($comment, 'ACS') !== false)) {
                    
                    // Delete this NAT rule
                    $API->write('/ip/firewall/nat/remove', false);
                    $API->write('=.id=' . $rule['.id']);
                    $API->read();
                    
                    $deleted_rules[] = $rulePort;
                }
            }

            $API->disconnect();

            return [
                'success' => true,
                'message' => 'Berhasil menghapus ' . count($deleted_rules) . ' NAT rules dari MikroTik',
                'deleted_ports' => $deleted_rules
            ];
        } catch (Exception $e) {
            $API->disconnect();
            return [
                'success' => false,
                'message' => 'Error MikroTik: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate docker-compose.yml content
     */
        private function generateDockerCompose($port) {
                $bundle = $this->getPortBundle($port);
        return <<<YAML
services:
  mongo:
    image: mongo:4.4
    container_name: genieacs-mongo-{$port}
    restart: always
    volumes:
      - ./mongo:/data/db

  genieacs:
    image: deandegil/genieacs
    container_name: genieacs-{$port}
    restart: always
    depends_on:
      - mongo
    ports:
            - "{$bundle['ui']}:3000"
            - "{$bundle['cwmp']}:7547"
            - "{$bundle['nbi']}:7557"
            - "{$bundle['fs']}:7567"
    environment:
      GENIEACS_MONGODB_CONNECTION_URL: mongodb://mongo/genieacs
      GENIEACS_UI_JWT_SECRET: quenbysecret
YAML;
    }

    /**
     * Import virtualparameter data to MongoDB using mongorestore
     */
    private function importBackupData($container_name) {
        $mongo_container = 'genieacs-mongo-' . str_replace('acs_', '', $container_name);

        $output = [];
        $return_var = 0;
        $safe_container = preg_replace('/[^a-zA-Z0-9_.-]/', '', $mongo_container);
        
        // Use mongorestore from temporary mongo:4.4 tools container
        // This restores all BSON files from virtualparameter directory to genieacs database.
        // genieacs.locks is excluded: GenieACS creates/owns that collection itself as soon as
        // Mongo is reachable, racing our --drop restore of the same (always-empty) collection
        // and causing a spurious NamespaceExists failure that aborted the whole server creation.
        $cmd = "docker run --rm --network=container:" . $safe_container
            . " -v " . escapeshellarg($this->backup_dir) . ":/backup mongo:4.4 "
            . "mongorestore --host 127.0.0.1 --port 27017 --db=genieacs --drop --nsExclude=genieacs.locks /backup 2>&1";
        
        @exec($cmd, $output, $return_var);

        if ($return_var === 0) {
            return [
                'success' => true,
                'message' => 'Import virtualparameter berhasil',
                'output' => $output
            ];
        }

        // mongorestore can exit non-zero purely because it raced GenieACS's own
        // startup while creating one of GenieACS's internally-managed collections
        // (NamespaceExists on locks/tasks/cache/etc, always with 0 documents in
        // that collection's dump). The actual data restore still completed fine
        // in that case, so trust mongorestore's own summary line over its exit
        // code: if it reports zero failed documents, treat the import as a success.
        $summary = implode(' ', $output);
        if (preg_match('/(\d+) document\(s\) restored successfully\.\s*0 document\(s\) failed to restore/', $summary)) {
            return [
                'success' => true,
                'message' => 'Import virtualparameter berhasil',
                'output' => $output
            ];
        }

        return [
            'success' => false,
            'message' => 'Gagal import virtualparameter. Cek output detail.',
            'output' => $output
        ];
    }

    /**
     * Assign server to user
     */
    public function assignServerToUser($server_id, $user_id) {
        $sql = "INSERT IGNORE INTO acs_user_server_assignment (user_id, server_id)
                VALUES (" . intval($user_id) . ", " . intval($server_id) . ")";
        return mysqli_query($this->conn, $sql);
    }

    /**
     * Remove server assignment from user
     */
    public function unassignServerFromUser($server_id, $user_id) {
        $sql = "DELETE FROM acs_user_server_assignment
                WHERE user_id = " . intval($user_id) . " AND server_id = " . intval($server_id);
        return mysqli_query($this->conn, $sql);
    }

    /**
     * Docker operations: start, stop, restart, delete
     */
    public function dockerOperation($server_id, $operation) {
        $server = $this->getServer($server_id);
        
        if (!$server) {
            return ['success' => false, 'message' => 'Server tidak ditemukan atau akses ditolak'];
        }

        // Only ADMIN can control other users' containers
        if (!$this->isAdmin() && $server['owner_id'] != $this->user_id) {
            return ['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengontrol container ini'];
        }

        if (!empty($server['is_external'])) {
            if ($operation === 'delete') {
                if (!$this->isAdmin()) {
                    return ['success' => false, 'message' => 'Only ADMIN can delete servers'];
                }
                mysqli_query($this->conn, "DELETE FROM acs_user_server_assignment WHERE server_id = " . intval($server_id));
                mysqli_query($this->conn, "DELETE FROM acs_devices WHERE server_id = " . intval($server_id));
                mysqli_query($this->conn, "DELETE FROM acs_servers WHERE id = " . intval($server_id));
                return [
                    'success' => true,
                    'message' => 'Registrasi server eksternal dihapus. Server aslinya tidak disentuh.'
                ];
            }

            return [
                'success' => false,
                'message' => 'Start/Stop/Restart tidak berlaku untuk server eksternal. Kelola langsung di server aslinya.'
            ];
        }

        $container_name = $server['container_name'];
        $compose_dir = dirname(__FILE__) . '/acs_containers/' . $container_name;

        if (!is_dir($compose_dir)) {
            return ['success' => false, 'message' => 'Directory container tidak ditemukan'];
        }

        $current_dir = getcwd();
        chdir($compose_dir);

        $output = [];
        $return_var = -1;

        switch ($operation) {
            case 'start':
                @exec("docker compose start 2>&1", $output, $return_var);
                $new_status = ($return_var === 0) ? 'RUNNING' : 'ERROR';
                break;
            
            case 'stop':
                @exec("docker compose stop 2>&1", $output, $return_var);
                $new_status = ($return_var === 0) ? 'STOPPED' : 'ERROR';
                break;
            
            case 'restart':
                @exec("docker compose restart 2>&1", $output, $return_var);
                $new_status = ($return_var === 0) ? 'RUNNING' : 'ERROR';
                break;
            
            case 'delete':
                if (!$this->isAdmin()) {
                    chdir($current_dir);
                    return ['success' => false, 'message' => 'Only ADMIN can delete containers'];
                }
                
                @exec("docker compose down -v 2>&1", $output, $return_var);
                
                if ($return_var === 0) {
                    // Delete MikroTik port forwarding rules (don't fail if this errors)
                    try {
                        $forward_result = $this->deleteMikroTikPortForwarding($server['port'], $server['nama_server']);
                        if ($forward_result['success']) {
                            $output[] = 'MikroTik NAT rules deleted: ' . ($forward_result['deleted_ports'] ? implode(', ', $forward_result['deleted_ports']) : '0');
                        } else {
                            $output[] = 'Warning: ' . $forward_result['message'];
                        }
                    } catch (Exception $e) {
                        $output[] = 'Warning: MikroTik cleanup failed - ' . $e->getMessage();
                    }
                    
                    // Delete database records
                    mysqli_query($this->conn, "DELETE FROM acs_user_server_assignment WHERE server_id = " . intval($server_id));
                    mysqli_query($this->conn, "DELETE FROM acs_devices WHERE server_id = " . intval($server_id));
                    mysqli_query($this->conn, "DELETE FROM acs_servers WHERE id = " . intval($server_id));
                    
                    // Delete directory
                    $this->deleteDirectory($compose_dir);
                }
                
                chdir($current_dir);
                return [
                    'success' => $return_var === 0,
                    'message' => $return_var === 0 ? 'Container berhasil dihapus' : 'Gagal menghapus container',
                    'output' => $output
                ];
            
            default:
                chdir($current_dir);
                return ['success' => false, 'message' => 'Invalid operation'];
        }

        chdir($current_dir);

        // Update server status
        if (isset($new_status)) {
            mysqli_query($this->conn, "UPDATE acs_servers SET status = '$new_status' WHERE id = " . intval($server_id));
        }

        return [
            'success' => $return_var === 0,
            'message' => $return_var === 0 ? 'Operasi berhasil' : 'Operasi gagal',
            'output' => $output
        ];
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Get container status from Docker
     */
    public function getContainerStatus($container_name) {
        $container = trim((string)$container_name);
        if ($container === '') {
            return 'NOT_FOUND';
        }

        $output = [];
        $code = -1;
        @exec("docker inspect --format='{{.State.Status}}' " . escapeshellarg($container) . " 2>&1", $output, $code);

        if ($code !== 0) {
            $errText = strtolower(trim(implode(' ', $output)));
            if (strpos($errText, 'no such object') !== false || strpos($errText, 'no such container') !== false) {
                return 'NOT_FOUND';
            }
            if (strpos($errText, 'command not found') !== false
                || strpos($errText, 'not recognized') !== false
                || strpos($errText, 'cannot find the file') !== false
                || strpos($errText, 'is not recognized') !== false) {
                return 'ERROR';
            }
            return 'UNKNOWN';
        }

        $state = strtolower(trim((string)($output[0] ?? '')));
        if ($state === 'running') {
            return 'RUNNING';
        }
        if ($state === 'exited' || $state === 'dead') {
            return 'STOPPED';
        }
        if ($state === 'created' || $state === 'restarting' || $state === 'paused') {
            return 'CREATING';
        }

        return 'UNKNOWN';
    }
    
    /**
     * Recursively extract all parameters from nested GenieACS device structure
     * Converts nested paths to dot notation (e.g., InternetGatewayDevice.DeviceInfo.HardwareVersion)
     */
    private function extractAllParameters($data, $prefix = '', $maxDepth = 5, $currentDepth = 0, &$paramCount = 0) {
        $result = [];
        
        // Safety limits: max depth and max total parameters
        if ($currentDepth >= $maxDepth || !is_array($data) || $paramCount >= 1000) {
            return $result;
        }
        
        foreach ($data as $key => $value) {
            // Check parameter count limit on each iteration
            if ($paramCount >= 1000) {
                break;
            }
            
            // Skip internal GenieACS keys
            if ($key === '_object' || $key === '_writable' || $key === '_timestamp' || 
                $key === '_deviceId' || $key === '_registered' || $key === '_lastInform' ||
                $key === '_type' || $key === '_tags') {
                continue;
            }
            
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            
            // If value is array and has _value key, extract it
            if (is_array($value) && isset($value['_value'])) {
                $result[$fullKey] = $value['_value'];
                $paramCount++;
            }
            // If value is array but not a GenieACS structured value, recurse
            elseif (is_array($value) && !isset($value['_value'])) {
                // Recurse into nested structure
                $nested = $this->extractAllParameters($value, $fullKey, $maxDepth, $currentDepth + 1, $paramCount);
                // Use + operator for better performance than array_merge
                $result = $result + $nested;
            }
            // Scalar value
            elseif (!is_array($value) && !is_object($value)) {
                $result[$fullKey] = $value;
                $paramCount++;
            }
        }
        
        return $result;
    }

    /**
     * Sync devices from GenieACS to database
     */
    public function syncDevices($server_id) {
        $server = $this->getServer($server_id);
        
        if (!$server) {
            return ['success' => false, 'message' => 'Server tidak ditemukan'];
        }

        // Use stored NBI port, falling back to base_port + 2 for older rows
        $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : intval($server['port']) + 2;
        $acs_url = "http://{$server['domain']}:{$nbi_port}";

        // Log sync attempt
        error_log("[ACS SYNC] Starting sync for server: {$server['nama_server']} at {$acs_url} (NBI port: {$nbi_port})");
        
        // Call GenieACS API to get devices
        $devices = $this->callGenieACSAPI($acs_url, '/devices', $server['username_acs'], $server['password_acs']);
        
        error_log("[ACS SYNC] API Response: " . json_encode($devices));
        
        $synced = 0;
        $owner_id = $server['owner_id'];

        // Primary method: sync from GenieACS API
        if ($devices && is_array($devices) && count($devices) > 0) {
            error_log("[ACS SYNC] Found " . count($devices) . " devices from API");
            
            // Log sample device structure for debugging
            if (count($devices) > 0) {
                error_log("[ACS SYNC] Sample device structure: " . json_encode(array_slice($devices, 0, 1)));
            }
            
            // Clear existing devices for this server
            $delete_result = mysqli_query($this->conn, "DELETE FROM acs_devices WHERE server_id = " . intval($server_id));
            error_log("[ACS SYNC] Cleared old devices, result: " . ($delete_result ? 'OK' : 'FAIL'));
            
            foreach ($devices as $device) {
                $serial = $device['_id'] ?? '';
                if (empty($serial)) {
                    error_log("[ACS SYNC] Skipping device with empty _id");
                    continue;
                }

                // Extract values safely - try multiple paths since NBI API structure may vary
                $manufacturer = $this->safeGet($device, ['DeviceID', 'Manufacturer', '_value']) ?? 
                               $this->safeGet($device, ['DeviceID', 'Manufacturer']) ?? 
                               $device['Manufacturer'] ?? '';
                
                $oui = $this->safeGet($device, ['DeviceID', 'OUI', '_value']) ?? 
                      $this->safeGet($device, ['DeviceID', 'OUI']) ?? 
                      $device['OUI'] ?? '';
                
                $product_class = $this->safeGet($device, ['DeviceID', 'ProductClass', '_value']) ?? 
                                $this->safeGet($device, ['DeviceID', 'ProductClass']) ?? 
                                $device['ProductClass'] ?? '';
                
                $hw_version = $this->safeGet($device, ['HardwareVersion', '_value']) ?? 
                             $this->safeGet($device, ['HardwareVersion']) ?? 
                             $device['HardwareVersion'] ?? '';
                
                $sw_version = $this->safeGet($device, ['SoftwareVersion', '_value']) ?? 
                             $this->safeGet($device, ['SoftwareVersion']) ?? 
                             $device['SoftwareVersion'] ?? '';
                
                $connection_url = $this->safeGet($device, ['ManagementServer', 'ConnectionRequestURL', '_value']) ?? 
                                 $this->safeGet($device, ['ManagementServer', 'ConnectionRequestURL']) ?? 
                                 $device['ConnectionRequestURL'] ?? '';
                
                $mac_address = $this->safeGet($device, ['MACAddress', '_value']) ?? 
                              $this->safeGet($device, ['MACAddress']) ?? 
                              $device['MACAddress'] ?? '';
                
                // Handle timestamp - _lastInform may be timestamp in milliseconds or string
                $last_inform_raw = $device['_lastInform'] ?? null;
                if (is_numeric($last_inform_raw)) {
                    // Convert from milliseconds to seconds if needed
                    $last_inform = intval($last_inform_raw);
                    if ($last_inform > 10000000000) { // Milliseconds (13 digits)
                        $last_inform = intval($last_inform / 1000);
                    }
                } else {
                    $last_inform = strtotime($last_inform_raw ?? 'now');
                }
                
                // Ensure we have valid timestamp
                if ($last_inform <= 0 || $last_inform > time() + 3600) {
                    $last_inform = time();
                }
                
                $is_online = (time() - $last_inform) < 600 ? 'ONLINE' : 'OFFLINE';
                
                // Extract IP from connection URL or other field
                $ip_address = '';
                if (strpos($connection_url, '://') !== false) {
                    $parts = parse_url($connection_url);
                    $ip_address = $parts['host'] ?? '';
                } else {
                    $ip_address = $this->safeGet($device, ['WANIPAddress', '_value']) ?? 
                                 $this->safeGet($device, ['WANIPAddress']) ?? '';
                }

                // Extract ALL parameters from device (including nested structures)
                $all_extracted_params = [];
                $paramCount = 0;
                
                try {
                    // Extract VirtualParameters if exists
                    if (isset($device['VirtualParameters']) && is_array($device['VirtualParameters'])) {
                        $vparams = $this->extractAllParameters($device['VirtualParameters'], 'VirtualParameters', 5, 0, $paramCount);
                        $all_extracted_params = array_merge($all_extracted_params, $vparams);
                    }
                    
                    // Extract InternetGatewayDevice parameters (TR-069 standard)
                    if (isset($device['InternetGatewayDevice']) && is_array($device['InternetGatewayDevice'])) {
                        $igdparams = $this->extractAllParameters($device['InternetGatewayDevice'], 'InternetGatewayDevice', 5, 0, $paramCount);
                        $all_extracted_params = array_merge($all_extracted_params, $igdparams);
                    }
                    
                    // Extract Device root parameters
                    if (isset($device['Device']) && is_array($device['Device'])) {
                        $deviceparams = $this->extractAllParameters($device['Device'], 'Device', 5, 0, $paramCount);
                        $all_extracted_params = array_merge($all_extracted_params, $deviceparams);
                    }
                } catch (Exception $e) {
                    error_log("[ACS SYNC ERROR] Failed to extract parameters for $serial: " . $e->getMessage());
                }
                
                $virtual_parameters = !empty($all_extracted_params) ? json_encode($all_extracted_params) : null;
                
                error_log("[ACS SYNC] Device $serial extracted " . count($all_extracted_params) . " parameters from nested structure");

                $sql = "INSERT INTO acs_devices (
                            server_id, device_id, serial_number, manufacturer, oui, product_class,
                            hardware_version, software_version, ip_address, mac_address, last_inform,
                            rx_power, tx_power, status, owner_id, virtual_parameters
                        ) VALUES (
                            " . intval($server_id) . ",
                            '" . mysqli_real_escape_string($this->conn, $serial) . "',
                            '" . mysqli_real_escape_string($this->conn, $serial) . "',
                            '" . mysqli_real_escape_string($this->conn, $manufacturer) . "',
                            '" . mysqli_real_escape_string($this->conn, $oui) . "',
                            '" . mysqli_real_escape_string($this->conn, $product_class) . "',
                            '" . mysqli_real_escape_string($this->conn, $hw_version) . "',
                            '" . mysqli_real_escape_string($this->conn, $sw_version) . "',
                            '" . mysqli_real_escape_string($this->conn, $ip_address) . "',
                            '" . mysqli_real_escape_string($this->conn, $mac_address) . "',
                            '" . mysqli_real_escape_string($this->conn, date('Y-m-d H:i:s', $last_inform)) . "',
                            NULL,
                            NULL,
                            '" . $is_online . "',
                            " . intval($owner_id) . ",
                            " . ($virtual_parameters ? "'" . mysqli_real_escape_string($this->conn, $virtual_parameters) . "'" : "NULL") . "
                        )";
                
                error_log("[ACS SYNC] Inserting device: $serial | Mfg: $manufacturer | OUI: $oui | Product: $product_class | Status: $is_online");
                
                if (!mysqli_query($this->conn, $sql)) {
                    error_log("[ACS SYNC] ERROR inserting device $serial: " . mysqli_error($this->conn));
                } else {
                    $synced++;
                    error_log("[ACS SYNC] Successfully inserted device: $serial");
                }
            }

            // Log sync
            mysqli_query($this->conn, "INSERT INTO acs_sync_log (server_id, sync_type, devices_synced, status) 
                                        VALUES ($server_id, 'devices', $synced, 'SUCCESS')");

            error_log("[ACS SYNC] Sync completed: $synced devices synced");

            return [
                'success' => true,
                'message' => "$synced devices synced from GenieACS API",
                'devices_synced' => $synced,
                'method' => 'genieacs_api'
            ];
        }

        error_log("[ACS SYNC] API returned no devices, attempting fallback");

        // Fallback method: sync based on PPPoE active users matched with pelanggan
        $fallback_result = $this->syncDevicesFromPPPoE($server_id, $owner_id);
        
        if ($fallback_result['success']) {
            error_log("[ACS SYNC] Fallback sync successful");
            return $fallback_result;
        }

        error_log("[ACS SYNC] Both methods failed");
        return ['success' => false, 'message' => 'Gagal sync dari GenieACS API dan PPPoE fallback'];
    }

    /**
     * Safely extract nested array values with fallback
     */
    private function safeGet($array, $keys) {
        foreach ($keys as $key) {
            if (!is_array($array) || !isset($array[$key])) {
                return null;
            }
            $array = $array[$key];
        }
        return $array;
    }

    /**
     * Fallback: Sync devices based on PPPoE active users matched with customer data
     */
    private function syncDevicesFromPPPoE($server_id, $owner_id) {
        // Load config for RouterOS API
        $config_file = dirname(__FILE__) . '/config.json';
        if (!file_exists($config_file)) {
            return ['success' => false, 'message' => 'Config file tidak ditemukan'];
        }

        $config = json_decode(file_get_contents($config_file), true);
        if (!$config) {
            return ['success' => false, 'message' => 'Config file tidak valid'];
        }

        // Load RouterOS API class
        if (!file_exists(dirname(__FILE__) . '/routeros_api.class.php')) {
            return ['success' => false, 'message' => 'RouterOS API class tidak ditemukan'];
        }

        require_once dirname(__FILE__) . '/routeros_api.class.php';

        // Get all MikroTik servers for this owner
        $servers_query = "SELECT * FROM server WHERE user_id = " . intval($owner_id);
        $servers_result = mysqli_query($this->conn, $servers_query);
        
        if (!$servers_result) {
            return ['success' => false, 'message' => 'Gagal mengambil data server MikroTik'];
        }

        $synced = 0;
        $pppoe_data = [];

        // Collect PPPoE active from all servers
        while ($srv = mysqli_fetch_assoc($servers_result)) {
            $API = new RouterosAPI();
            
            // Suppress any RouterOS API errors/warnings
            if (@$API->connect($srv['IP'], $srv['PEMILIK'], $srv['PASSWORD'])) {
                try {
                    $pppoeActives = @$API->comm("/ppp/active/print");
                    
                    if (is_array($pppoeActives)) {
                        foreach ($pppoeActives as $ppp) {
                            $username = $ppp['name'] ?? '';
                            $ip = $ppp['address'] ?? '';
                            
                            if (!empty($username) && !empty($ip)) {
                                $pppoe_data[] = [
                                    'username' => $username,
                                    'ip' => $ip,
                                    'caller_id' => $ppp['caller-id'] ?? '',
                                    'uptime' => $ppp['uptime'] ?? ''
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Silent fail for individual server
                }
                
                @$API->disconnect();
            }
        }

        if (empty($pppoe_data)) {
            return ['success' => false, 'message' => 'Tidak ada PPPoE active ditemukan'];
        }

        // Match PPPoE users with pelanggan database and create device entries
        foreach ($pppoe_data as $pppoe) {
            // Find customer by username (IDPEL)
            $customer_query = "SELECT * FROM pelanggan WHERE IDPEL = '" . mysqli_real_escape_string($this->conn, $pppoe['username']) . "' AND PEMILIK = (SELECT PEMILIK FROM server WHERE user_id = " . intval($owner_id) . " LIMIT 1)";
            $customer_result = mysqli_query($this->conn, $customer_query);
            
            if ($customer = mysqli_fetch_assoc($customer_result)) {
                // Create device entry based on customer data
                $device_id = $pppoe['username'] . '_ont';
                
                $sql = "INSERT INTO acs_devices (
                            server_id, device_id, serial_number, manufacturer, oui, product_class,
                            hardware_version, software_version, ip_address, mac_address, last_inform,
                            rx_power, tx_power, status, owner_id
                        ) VALUES (
                            " . intval($server_id) . ",
                            '" . mysqli_real_escape_string($this->conn, $device_id) . "',
                            '" . mysqli_real_escape_string($this->conn, $pppoe['caller_id'] ?: $pppoe['username']) . "',
                            'Unknown',
                            '',
                            '" . mysqli_real_escape_string($this->conn, $customer['PAKET']) . "',
                            '',
                            '',
                            '" . mysqli_real_escape_string($this->conn, $pppoe['ip']) . "',
                            '" . mysqli_real_escape_string($this->conn, $pppoe['caller_id']) . "',
                            '" . date('Y-m-d H:i:s') . "',
                            NULL,
                            NULL,
                            'ONLINE',
                            " . intval($owner_id) . "
                        ) ON DUPLICATE KEY UPDATE
                            ip_address = '" . mysqli_real_escape_string($this->conn, $pppoe['ip']) . "',
                            last_inform = '" . date('Y-m-d H:i:s') . "',
                            status = 'ONLINE',
                            updated_at = CURRENT_TIMESTAMP";
                
                if (mysqli_query($this->conn, $sql)) {
                    $synced++;
                }
            }
        }

        // Log sync
        mysqli_query($this->conn, "INSERT INTO acs_sync_log (server_id, sync_type, devices_synced, status) 
                                    VALUES ($server_id, 'devices_pppoe_fallback', $synced, 'SUCCESS')");

        return [
            'success' => true,
            'message' => "$synced devices synced from PPPoE active (fallback method)",
            'devices_synced' => $synced,
            'method' => 'pppoe_fallback'
        ];
    }

    /**
     * Call GenieACS API
     */
    private function callGenieACSAPI($base_url, $endpoint, $username, $password) {
        $url = $base_url . $endpoint;
        
        $ch = @curl_init($url);
        if (!$ch) {
            return false;
        }
        
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        @curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        @curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = @curl_exec($ch);
        $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        
        if ($http_code === 200 && $response) {
            return @json_decode($response, true);
        }
        
        return false;
    }
}
?>
