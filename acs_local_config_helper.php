<?php
/**
 * ACS Local Config Helper
 * Handles setup of GenieACS from local Genie-ACS-alijayanet folder
 * with full authentication and user creation capabilities
 */

class ACSLocalConfigHelper {
    private $local_genie_path;
    private $docker_container_prefix;
    
    public function __construct($local_genie_path = null) {
        if ($local_genie_path === null) {
            $this->local_genie_path = dirname(__FILE__) . '/Genie-ACS-alijayanet';
        } else {
            $this->local_genie_path = $local_genie_path;
        }
        $this->docker_container_prefix = 'genieacs-mongo-';
    }
    
    /**
     * Validate local Genie-ACS folder has all required config files
     */
    public function validateLocalConfig() {
        if (!is_dir($this->local_genie_path)) {
            return [
                'valid' => false,
                'message' => 'Folder Genie-ACS tidak ditemukan: ' . $this->local_genie_path,
                'path' => $this->local_genie_path
            ];
        }
        
        $required_files = [
            'virtualParameters.bson' => 'Virtual Parameters Collection',
            'virtualParameters.metadata.json' => 'Virtual Parameters Metadata',
            'config.bson' => 'Configuration Collection',
            'config.metadata.json' => 'Configuration Metadata',
            'users.bson' => 'Users Collection',
            'users.metadata.json' => 'Users Metadata'
        ];
        
        $missing = [];
        $found = [];
        
        foreach ($required_files as $filename => $label) {
            $filepath = $this->local_genie_path . '/' . $filename;
            if (file_exists($filepath)) {
                $size = filesize($filepath);
                $found[] = $label . ' (' . $filename . ', ' . $this->formatBytes($size) . ')';
            } else {
                $missing[] = $label . ' (' . $filename . ')';
            }
        }
        
        return [
            'valid' => empty($missing),
            'message' => empty($missing) ? 'Semua file konfigurasi ditemukan' : 'File hilang: ' . implode(', ', $missing),
            'path' => $this->local_genie_path,
            'found_files' => $found,
            'missing_files' => $missing
        ];
    }
    
    /**
     * Setup full authentication user in GenieACS MongoDB
     * with unrestricted access to create/manage other users
     */
    public function setupFullAuthUser($mongo_container, $username, $password, $port = null) {
        // Validate inputs
        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => 'Username dan password harus diisi'
            ];
        }
        
        if (strlen($password) < 6) {
            return [
                'success' => false,
                'message' => 'Password minimal 6 karakter'
            ];
        }
        
        // Create full auth user document
        $user_document = $this->createFullAuthUserDocument($username, $password);
        
        // Try to insert/update via mongosh (MongoDB shell)
        $result = $this->insertUserViaMongoSH($mongo_container, $user_document);
        if ($result['success']) {
            return $result;
        }
        
        // Fallback: Try via mongoimport
        $result = $this->insertUserViaMongoimport($mongo_container, $user_document, $port);
        return $result;
    }
    
    /**
     * Create a full auth user document with all permissions
     */
    private function createFullAuthUserDocument($username, $password) {
        // Hash password using bcrypt
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        $document = [
            '_id' => $username,
            'password' => $hashedPassword,
            'email' => $username . '@quenbytekniksejahtera.com',
            'roles' => [
                'admin',
                'manager',
                'provisioner',
                'support'
            ],
            'permissions' => [
                // Full access to all resources
                'users.*',
                'presets.*',
                'provisions.*',
                'virtualParameters.*',
                'config.*',
                'devices.*',
                'faults.*',
                'tasks.*',
                'admin.*',
                'api.*'
            ],
            'groups' => ['admin', 'users'],
            'canCreateUsers' => true,
            'canEditUsers' => true,
            'canDeleteUsers' => true,
            'canManagePermissions' => true,
            'fullAccess' => true,
            'restrictions' => [],
            'createdAt' => round(microtime(true) * 1000),
            'lastModified' => round(microtime(true) * 1000),
            'active' => true,
            'locked' => false
        ];
        
        return $document;
    }
    
    /**
     * Insert user via mongosh command
     */
    private function insertUserViaMongoSH($mongo_container, $user_document) {
        $safe_container = preg_replace('/[^a-zA-Z0-9_.-]/', '', $mongo_container);
        
        // Prepare JavaScript command for mongosh
        $username = $user_document['_id'];
        $password = $user_document['password'];
        
        // Build mongosh command
        $js_cmd = <<<'JSCMD'
db.users.updateOne(
  { _id: "%USERNAME%" },
  {
    $set: {
      _id: "%USERNAME%",
      password: "%PASSWORD%",
      email: "%USERNAME%@quenbytekniksejahtera.com",
      roles: ["admin", "manager", "provisioner", "support"],
      permissions: ["users.*", "presets.*", "provisions.*", "virtualParameters.*", "config.*", "devices.*", "faults.*", "tasks.*", "admin.*", "api.*"],
      groups: ["admin", "users"],
      canCreateUsers: true,
      canEditUsers: true,
      canDeleteUsers: true,
      canManagePermissions: true,
      fullAccess: true,
      restrictions: [],
      createdAt: %TIMESTAMP%,
      lastModified: %TIMESTAMP%,
      active: true,
      locked: false
    }
  },
  { upsert: true }
);
JSCMD;
        
        $js_cmd = str_replace('%USERNAME%', $username, $js_cmd);
        $js_cmd = str_replace('%PASSWORD%', $password, $js_cmd);
        $js_cmd = str_replace('%TIMESTAMP%', round(microtime(true) * 1000), $js_cmd);
        
        $output = [];
        $return_var = 0;
        
        $cmd = "docker exec " . $safe_container . " mongosh --db=genieacs --eval \"" 
            . str_replace('"', '\\"', $js_cmd) . "\" 2>&1";
        
        @exec($cmd, $output, $return_var);
        
        if ($return_var === 0) {
            return [
                'success' => true,
                'message' => 'User "' . $username . '" created dengan akses PENUH ke GenieACS (unrestricted)',
                'method' => 'mongosh',
                'output' => $output
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Gagal via mongosh, akan coba metode alternatif',
            'method' => 'mongosh',
            'output' => $output,
            'return_code' => $return_var
        ];
    }
    
    /**
     * Insert user via mongoimport (alternative method)
     */
    private function insertUserViaMongoimport($mongo_container, $user_document, $port = null) {
        $safe_container = preg_replace('/[^a-zA-Z0-9_.-]/', '', $mongo_container);
        
        // Create temporary JSON file
        $temp_file = sys_get_temp_dir() . '/genieacs_user_' . uniqid() . '.json';
        
        // Create JSONL format (one JSON object per line)
        $jsonl_content = json_encode($user_document) . "\n";
        
        if (!file_put_contents($temp_file, $jsonl_content)) {
            return [
                'success' => false,
                'message' => 'Gagal membuat file temporary untuk user'
            ];
        }
        
        $output = [];
        $return_var = 0;
        
        // Use mongoimport command
        $cmd = "docker run --rm --network=container:" . $safe_container
            . " -v " . escapeshellarg(sys_get_temp_dir()) . ":/tmp mongo:4.4 "
            . "mongoimport --host 127.0.0.1 --port 27017 --db=genieacs --collection=users "
            . "--jsonArray --upsert --file=/tmp/" . basename($temp_file) . " 2>&1";
        
        @exec($cmd, $output, $return_var);
        @unlink($temp_file);
        
        if ($return_var === 0) {
            return [
                'success' => true,
                'message' => 'User "' . $user_document['_id'] . '" created dengan akses PENUH via mongoimport',
                'method' => 'mongoimport',
                'output' => $output
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Gagal membuat user di MongoDB menggunakan mongoimport',
            'method' => 'mongoimport',
            'output' => $output,
            'return_code' => $return_var
        ];
    }
    
    /**
     * Get list of all BSON collections in local folder
     */
    public function getAvailableCollections() {
        $collections = [];
        
        if (!is_dir($this->local_genie_path)) {
            return $collections;
        }
        
        $files = scandir($this->local_genie_path);
        
        foreach ($files as $file) {
            if (substr($file, -5) === '.bson') {
                $collection_name = substr($file, 0, -5);
                $metadata_file = $collection_name . '.metadata.json';
                $filepath = $this->local_genie_path . '/' . $file;
                $metadata_path = $this->local_genie_path . '/' . $metadata_file;
                
                $size = filesize($filepath);
                $metadata = file_exists($metadata_path) ? json_decode(file_get_contents($metadata_path), true) : [];
                
                $collections[] = [
                    'name' => $collection_name,
                    'bson_file' => $file,
                    'metadata_file' => $metadata_file,
                    'bson_size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'metadata' => $metadata
                ];
            }
        }
        
        return $collections;
    }
    
    /**
     * Copy local virtual parameters to volume mount
     */
    public function copyConfigToContainerVolume($container_name, $target_path = '/backup') {
        // This would copy the local Genie-ACS folder to container volume
        // Typically done via docker volume mount or docker cp
        
        if (!is_dir($this->local_genie_path)) {
            return [
                'success' => false,
                'message' => 'Folder sumber tidak ditemukan'
            ];
        }
        
        $safe_container = preg_replace('/[^a-zA-Z0-9_.-]/', '', $container_name);
        $output = [];
        $return_var = 0;
        
        // Copy local folder to container
        $cmd = "docker cp " . escapeshellarg($this->local_genie_path) . " "
            . $safe_container . ":" . $target_path . " 2>&1";
        
        @exec($cmd, $output, $return_var);
        
        if ($return_var === 0) {
            return [
                'success' => true,
                'message' => 'Folder Genie-ACS berhasil di-copy ke container',
                'output' => $output
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Gagal copy folder ke container',
            'output' => $output
        ];
    }
    
    /**
     * Get path to local Genie-ACS folder
     */
    public function getLocalConfigPath() {
        return $this->local_genie_path;
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// Example usage when acs_add_server.php calls createServer:
// Include this helper and use it to validate + setup auth before/after container creation
