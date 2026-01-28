<?php
require_once 'auth.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

// Run git pull
$output = [];
$return_var = 0;
// Redirect stderr to stdout to capture errors
exec('git pull 2>&1', $output, $return_var);

echo json_encode([
    'success' => $return_var === 0,
    'output' => implode("\n", $output)
]);
?>