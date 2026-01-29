<?php
// Simple logging helper

function writeLog($message, $level = 'INFO') {
    $logFile = 'dashboard.log';

    // Create file if it doesn't exist
    if (!file_exists($logFile)) {
        touch($logFile);
        @chmod($logFile, 0666);
    }

    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;

    // Append to file with locking
    file_put_contents($logFile, $formattedMessage, FILE_APPEND | LOCK_EX);
}
