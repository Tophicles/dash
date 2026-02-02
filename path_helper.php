<?php
// Function to get the data directory path
function getDataDir() {
    $envPath = getenv('CONFIG_DIR');
    if ($envPath) {
        return rtrim($envPath, '/') . '/';
    }
    return __DIR__ . '/';
}

// Define the constant if not already defined
if (!defined('DATA_DIR')) {
    define('DATA_DIR', getDataDir());
}
?>
