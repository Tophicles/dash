<?php
require_once 'auth.php';
require_once 'encryption_helper.php';
require_once 'config_helper.php';
requireLogin();

header('Content-Type: application/json');

$serversFile = 'servers.json';
$config = ['refreshSeconds' => 5, 'servers' => []];

if (file_exists($serversFile)) {
    $config = json_decode(file_get_contents($serversFile), true) ?: $config;
}

// Schema Migration
if (validateAndMigrateConfig($config)) {
    // We only save basic structure, secrets are already encrypted in the file
    // But validateAndMigrate works on the loaded array.
    // If we save it back, we need to be careful not to double-encrypt or mess up?
    // Actually, validateAndMigrate touches keys like ssh_host, etc. It doesn't touch apiKey/token.
    // So safe to save back.
    file_put_contents($serversFile, json_encode($config, JSON_PRETTY_PRINT));
    @chmod($serversFile, 0666);
}

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
