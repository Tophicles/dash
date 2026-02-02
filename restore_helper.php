<?php
require_once 'path_helper.php';
require_once 'logging.php';

function performRestore($zipFilePath, $user = 'System') {
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => 'PHP Zip extension not installed'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath) === true) {
        // Validation: Check for essential files
        if ($zip->locateName('users.json') === false || $zip->locateName('key.php') === false) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid backup: missing users.json or key.php'];
        }

        // Restore files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Security check: Prevent directory traversal
            if (strpos($filename, '../') !== false || strpos($filename, '..\\') !== false) {
                continue;
            }

            // Only allow specific files and keys directory
            $jsonFiles = ['users.json', 'servers.json', 'activity.json', 'watcher_state.json'];
            $isKeyFile = ($filename === 'key.php');
            $isKeyDir = (strpos($filename, 'keys/') === 0);

            if (in_array($filename, $jsonFiles)) {
                // Extract to DB_DIR
                $zip->extractTo(DATA_DIR, $filename);
                if (file_exists(DATA_DIR . $filename)) {
                    // Ensure DB_DIR exists
                    if (!file_exists(DB_DIR)) mkdir(DB_DIR, 0777, true);
                    rename(DATA_DIR . $filename, DB_DIR . $filename);
                    @chmod(DB_DIR . $filename, 0666);
                }
            } elseif ($isKeyFile) {
                $zip->extractTo(DATA_DIR, $filename);
                if (file_exists(DATA_DIR . $filename)) @chmod(DATA_DIR . $filename, 0600);
            } elseif ($isKeyDir) {
                $zip->extractTo(DATA_DIR, $filename);
            }
        }

        $zip->close();

        // Protect keys dir permissions
        if (is_dir(DATA_DIR . 'keys')) {
             $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DATA_DIR . 'keys'));
             foreach ($iterator as $item) {
                 if ($item->isFile()) chmod($item, 0600);
             }
        }

        writeLog("System restored from backup by: $user", "WARN");
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Failed to open zip file'];
    }
}
?>
