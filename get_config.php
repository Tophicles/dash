<?php
header('Content-Type: application/json');
echo json_encode([
    'servers' => [
        ['name' => 'TestServer', 'type' => 'emby', 'url' => 'http://localhost', 'enabled' => true, 'id' => '1']
    ],
    'refreshSeconds' => 60
]);
?>