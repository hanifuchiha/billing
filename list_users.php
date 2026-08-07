<?php
/**
 * QUICK USER FINDER: List available users in database
 */
require_once 'koneksibilling.php';

echo "Available Users in Database:" . PHP_EOL;
echo "=============================" . PHP_EOL . PHP_EOL;

$query = "SELECT id, USERNAME, STATUS, grup FROM user ORDER BY STATUS DESC, USERNAME ASC";
$result = mysqli_query($conn, $query);
$count = 0;

if ($result && mysqli_num_rows($result) > 0) {
    printf("%-5s %-20s %-15s %-20s\n", "ID", "USERNAME", "STATUS", "GROUP");
    echo str_repeat("-", 65) . PHP_EOL;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $count++;
        printf("%-5d %-20s %-15s %-20s\n", 
            $row['id'], 
            $row['USERNAME'], 
            $row['STATUS'], 
            $row['grup'] ?? '-'
        );
    }
    echo str_repeat("-", 65) . PHP_EOL;
    echo "Total: $count users" . PHP_EOL;
} else {
    echo "No users found or query error" . PHP_EOL;
}

echo PHP_EOL . "Use these usernames for API testing." . PHP_EOL;
echo "Test with: /crm/billing/api/pelanggan_menunggak.php?username=USERNAME&password=PASSWORD" . PHP_EOL;

mysqli_close($conn);
?>
