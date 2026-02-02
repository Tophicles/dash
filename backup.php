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
    // Create Backup
    $zipFile = sys_get_temp_dir() . '/multidash_backup_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die("Failed to create zip file");
    }

    $filesToBackup = [
        'users.json',
        'servers.json',
        'activity.json',
        'watcher_state.json',
        'key.php'
    ];

    // Add root files
    foreach ($filesToBackup as $file) {
        if (file_exists(DATA_DIR . $file)) {
            $zip->addFile(DATA_DIR . $file, $file);
        }
    }

    // Add keys directory
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

    // Stream file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="multidash_backup_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($zipFile);
    unlink($zipFile);

    $user = getCurrentUser()['username'];
    writeLog("Backup created by user: $user", "INFO");
    exit;
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
            $allowed = ['users.json', 'servers.json', 'activity.json', 'watcher_state.json', 'key.php'];
            $isKey = strpos($filename, 'keys/') === 0;

            if (!in_array($filename, $allowed) && !$isKey) {
                continue;
            }

            // Extract to DATA_DIR
            $zip->extractTo(DATA_DIR, $filename);
        }

        $zip->close();

        // Fix permissions
        $filesToFix = ['users.json', 'servers.json', 'activity.json', 'watcher_state.json'];
        foreach ($filesToFix as $f) {
            if (file_exists(DATA_DIR . $f)) @chmod(DATA_DIR . $f, 0666);
        }

        // Protect key.php
        if (file_exists(DATA_DIR . 'key.php')) @chmod(DATA_DIR . 'key.php', 0600);

        // Protect keys dir
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
