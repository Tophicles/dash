<?php
require_once 'logging.php';
require_once 'path_helper.php';

define('SSH_KEY_DIR', DATA_DIR . 'keys');
define('SSH_PRIVATE_KEY_PATH', SSH_KEY_DIR . '/id_rsa');
define('SSH_PUBLIC_KEY_PATH', SSH_PRIVATE_KEY_PATH . '.pub');

function ensureKeyDir() {
    if (!file_exists(SSH_KEY_DIR)) {
        // Ensure parent DATA_DIR is writable before creating the keys subdirectory
        if (!is_writable(DATA_DIR)) {
            writeLog("SSH Key Generation Failed: DATA_DIR (" . DATA_DIR . ") is not writable.", "ERROR");
            return false;
        }
        if (!mkdir(SSH_KEY_DIR, 0700, true)) {
            return false;
        }
        @file_put_contents(SSH_KEY_DIR . '/.htaccess', "Deny from all");
    }
    return is_writable(SSH_KEY_DIR);
}

function getSSHPublicKey() {
    if (file_exists(SSH_PUBLIC_KEY_PATH)) {
        return file_get_contents(SSH_PUBLIC_KEY_PATH);
    }
    return null;
}

function generateSSHKeyPair() {
    if (!ensureKeyDir()) {
        writeLog("SSH Key Generation Failed: Key directory is not writable.", "ERROR");
        return false;
    }

    // Remove old keys
    if (file_exists(SSH_PRIVATE_KEY_PATH)) @unlink(SSH_PRIVATE_KEY_PATH);
    if (file_exists(SSH_PUBLIC_KEY_PATH)) @unlink(SSH_PUBLIC_KEY_PATH);

    // Some environments require HOME to be set for ssh-keygen to work properly
    $cmd = "HOME=/tmp ssh-keygen -t rsa -b 4096 -C \"multidash\" -f " . escapeshellarg(SSH_PRIVATE_KEY_PATH) . " -N \"\" -q 2>&1";
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        writeLog("ssh-keygen failed (Exit $returnVar): " . implode("\n", $output), "ERROR");
    }

    // Set correct permissions for private key (essential for SSH)
    if (file_exists(SSH_PRIVATE_KEY_PATH)) {
        chmod(SSH_PRIVATE_KEY_PATH, 0600);
    }

    return ($returnVar === 0 && file_exists(SSH_PUBLIC_KEY_PATH));
}

function executeSSHCommand($host, $port, $user, $command, $timeout = 10) {
    if (!file_exists(SSH_PRIVATE_KEY_PATH)) {
        return ['success' => false, 'error' => 'No SSH private key found. Generate one in settings.'];
    }

    // Construct the SSH command
    // -i: Identity file
    // -p: Port
    // -o StrictHostKeyChecking=no: Don't ask for fingerprint confirmation (risky but necessary for automation)
    // -o ConnectTimeout=5: Fail fast connection
    // -o BatchMode=yes: Don't ask for passwords

    // Use `timeout` command to prevent execution hang
    // Some environments require HOME to be set for ssh to work properly
    $sshCmd = sprintf(
        "timeout %d env HOME=/tmp ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 -o BatchMode=yes %s@%s %s 2>&1",
        (int)$timeout,
        escapeshellarg(SSH_PRIVATE_KEY_PATH),
        (int)$port,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($command)
    );

    $output = [];
    $returnVar = 0;
    exec($sshCmd, $output, $returnVar);

    $outputStr = implode("\n", $output);

    if ($returnVar === 0) {
        return ['success' => true, 'output' => $outputStr];
    } else {
        writeLog("SSH Command Failed: $sshCmd -> Return: $returnVar, Output: $outputStr", "ERROR");
        return ['success' => false, 'error' => "Exit Code $returnVar: " . $outputStr];
    }
}
?>