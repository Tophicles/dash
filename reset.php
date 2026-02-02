<?php
require_once 'auth.php';
require_once 'logging.php';
require_once 'path_helper.php';
require_once 'ssh_helper.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$confirmed = $input['confirmed'] ?? false;

if (!$confirmed) {
    echo json_encode(['success' => false, 'error' => 'Confirmation required']);
    exit;
}

// Log the panic event
writeLog("PANIC RESET INITIATED by user '" . getCurrentUser()['username'] . "'. Wiping system.", "ALERT");

try {
    // 1. Wipe DB Directory (Users, Servers, etc.)
    if (file_exists(DB_DIR)) {
        $files = glob(DB_DIR . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        // Ideally we keep the directory structure, but empty it.
        // Or we can remove the dir, but path_helper might complain if not recreated.
        // It recreates it on load if missing.
    }

    // 2. Wipe SSH Keys
    if (defined('SSH_KEY_DIR') && file_exists(SSH_KEY_DIR)) {
        $files = glob(SSH_KEY_DIR . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        // rmdir(SSH_KEY_DIR); // Keep the dir to avoid permission issues later? Or wipe it all.
        // Let's keep the dir structure to be safe, just empty files.
    }

    // 3. Wipe Encryption Key
    $keyFile = DATA_DIR . 'key.php';
    if (file_exists($keyFile)) {
        unlink($keyFile);
    }

    // 4. Wipe Logs (Last step, maybe?)
    // If we delete the log file, we lose the record of the reset.
    // However, "all information will be removed".
    // Let's write one final log entry then delete it?
    // Or just truncate it?
    // The requirement says "Log all actions in the logger." but also "all information will be removed".
    // Usually a factory reset clears logs too.
    // I will delete the log file.

    $logFile = DATA_DIR . 'dashboard.log';
    if (file_exists($logFile)) {
        unlink($logFile);
    }

    // 5. Logout
    logout();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Attempt to log failure if log file still exists or can be created
    writeLog("PANIC RESET FAILED: " . $e->getMessage(), "ERROR");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Reset failed: ' . $e->getMessage()]);
}
?>
