<?php
// api/pool.php - deprecated alias. The canonical IP Pool endpoint is api/ip_pool.php (kept at
// that filename because the Qbilling Android app hardcodes it). This file only exists in case
// something outside this codebase still points at "pool.php" specifically; forwards as-is.
require __DIR__ . '/ip_pool.php';
