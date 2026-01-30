<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
requireLogin();

header('Content-Type: application/json');

$serversFile = 'servers.json';
if (!file_exists($serversFile)) {
    echo json_encode(['refreshSeconds' => 5, 'servers' => []]);
    exit;
}

$config = json_decode(file_get_contents($serversFile), true);
$isAdmin = isAdmin();

if (isset($config['servers']) && is_array($config['servers'])) {
    foreach ($config['servers'] as &$server) {
        if ($isAdmin) {
            // Decrypt keys for admins so they can see/edit them in the dashboard
            if (isset($server['apiKey']) && !empty($server['apiKey'])) {
                $server['apiKey'] = decrypt($server['apiKey']);
            }
            if (isset($server['token']) && !empty($server['token'])) {
                $server['token'] = decrypt($server['token']);
            }
        } else {
            // Strip keys for non-admins for improved security
            unset($server['apiKey']);
            unset($server['token']);
        }
    }
}

echo json_encode($config);
