<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
require_once 'auth.php';
require_once 'logging.php';
require_once 'path_helper.php';
require_once 'restore_helper.php';

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
    $user = getCurrentUser()['username'];

    $result = performRestore($tmpName, $user);
    echo json_encode($result);
    exit;
}
?>
