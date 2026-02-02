<?php
require_once 'auth.php';
require_once 'logging.php';
require_once 'path_helper.php';

requireLogin();
requireAdmin();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['success' => false, 'error' => 'PHP Zip extension not installed']);
    } else {
        die("Error: PHP Zip extension (ZipArchive) is not installed on the server.");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'download';

    if ($action === 'generate') {
        $backupName = 'multidash_backup_' . date('Ymd_His') . '.zip';
        $zipFile = sys_get_temp_dir() . '/' . $backupName;
        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Failed to create zip file"]);
            exit;
        }

        // 1. Add DB files (JSONs)
        $filesToBackup = ['users.json', 'servers.json', 'activity.json', 'watcher_state.json'];
        foreach ($filesToBackup as $file) {
            if (file_exists(DB_DIR . $file)) {
                $zip->addFile(DB_DIR . $file, $file);
            }
        }

        // 2. Add Key File (Root level config)
        if (file_exists(DATA_DIR . 'key.php')) {
            $zip->addFile(DATA_DIR . 'key.php', 'key.php');
        }

        // 3. Add Keys Directory
        $keysDir = DATA_DIR . 'keys';
        if (is_dir($keysDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($keysDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = 'keys/' . substr($filePath, strlen(realpath($keysDir)) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        $zip->close();

        // Return JSON response for UI
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'downloadUrl' => 'backup.php?action=download&file=' . urlencode($backupName)
        ]);
        exit;
    }

    if ($action === 'download') {
        $fileName = $_GET['file'] ?? '';
        // Validate filename to prevent traversal
        if (!preg_match('/^multidash_backup_\d+_\d+\.zip$/', $fileName)) {
            die("Invalid filename");
        }

        $zipFile = sys_get_temp_dir() . '/' . $fileName;
        if (!file_exists($zipFile)) {
            die("File not found");
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($zipFile));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($zipFile);
        unlink($zipFile); // Delete after download

        $user = getCurrentUser()['username'];
        writeLog("Backup downloaded by user: $user", "INFO");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'File upload failed']);
        exit;
    }

    $tmpName = $_FILES['backup_file']['tmp_name'];
    $zip = new ZipArchive();

    if ($zip->open($tmpName) === true) {
        // Validation: Check for essential files
        if ($zip->locateName('users.json') === false || $zip->locateName('key.php') === false) {
            $zip->close();
            echo json_encode(['success' => false, 'error' => 'Invalid backup: missing users.json or key.php']);
            exit;
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
                // ZipArchive::extractTo extracts preserving paths. If zip has no path, it goes to root.
                // We want to force these into DB_DIR.
                // Extract to temp then move? Or simple: extract to DATA_DIR (if zip structure is flat) then move?
                // Plan: Extract to DATA_DIR. If it's a JSON, move to DB_DIR.
                $zip->extractTo(DATA_DIR, $filename);
                if (file_exists(DATA_DIR . $filename)) {
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

        $user = getCurrentUser()['username'];
        writeLog("System restored from backup by user: $user", "WARN");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to open zip file']);
    }
    exit;
}
?>
