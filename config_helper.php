<?php

function validateAndMigrateConfig(&$config) {
    $changed = false;

    if (!isset($config['servers']) || !is_array($config['servers'])) {
        $config['servers'] = [];
        $changed = true;
    }

    foreach ($config['servers'] as &$server) {
        // Ensure ID
        if (!isset($server['id'])) {
            $server['id'] = uniqid();
            $changed = true;
        }

        // Add Defaults
        $defaults = [
            'os_type' => 'linux',
            'ssh_port' => 22
        ];

        foreach ($defaults as $key => $val) {
            if (!isset($server[$key])) {
                $server[$key] = $val;
                $changed = true;
            }
        }

        // Remove deprecated fields
        $deprecated = ['color', 'ssh_host', 'ssh_user', 'ssh_service'];
        foreach ($deprecated as $key) {
            if (isset($server[$key])) {
                unset($server[$key]);
                $changed = true;
            }
        }
    }

    return $changed;
}
?>