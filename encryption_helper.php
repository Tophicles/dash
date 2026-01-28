<?php

/**
 * Gets or generates the secret encryption key.
 * The key is stored in key.php which is a PHP file to prevent direct access.
 */
function getSecretKey() {
    $keyFile = __DIR__ . '/key.php';
    if (!file_exists($keyFile)) {
        // Generate a random 32-byte key and store it as a hex string in a PHP file
        $key = bin2hex(random_bytes(32));
        $content = "<?php\n// This file was automatically generated for sensitive data encryption.\n// DO NOT share this file.\nreturn '$key';\n";
        file_put_contents($keyFile, $content);
        // Set restrictive permissions if possible
        @chmod($keyFile, 0600);
    }
    return require $keyFile;
}

/**
 * Encrypts data using AES-256-CBC.
 */
function encrypt($data) {
    if ($data === null || $data === '') return '';
    
    $key = hash('sha256', getSecretKey(), true);
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    
    if ($encrypted === false) {
        return '';
    }
    
    // Combine IV and encrypted data then encode to base64 for storage
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypts data using AES-256-CBC.
 */
function decrypt($data) {
    if ($data === null || $data === '') return '';
    
    $decoded = base64_decode($data);
    if ($decoded === false || strlen($decoded) <= 16) {
        // Probably not encrypted or corrupted
        return $data;
    }
    
    $key = hash('sha256', getSecretKey(), true);
    $iv = substr($decoded, 0, 16);
    $encrypted = substr($decoded, 16);
    
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    
    return ($decrypted === false) ? $data : $decrypted;
}
