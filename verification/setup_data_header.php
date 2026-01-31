<?php
// Create users.json - Correct structure (Associative Array)
$users = [
    'admin' => [
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'role' => 'admin',
        'created' => date('Y-m-d H:i:s')
    ]
];
file_put_contents('users.json', json_encode($users));

// Create servers.json
$servers = [
    'refreshSeconds' => 5,
    'servers' => [
        [
            'id' => 'srv_1',
            'name' => 'LinuxHeaderSrv',
            'type' => 'emby',
            'url' => 'http://localhost:8096',
            'enabled' => true,
            'os_type' => 'linux',
            'ssh_port' => 22,
            'ssh_initialized' => true // Assume already deployed
        ]
    ]
];
file_put_contents('servers.json', json_encode($servers));

echo "Data setup complete.\n";
?>