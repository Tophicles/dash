<?php

/**
 * Creates a cURL handle with standard options and configurable SSL verification.
 *
 * @param string $url The URL to fetch
 * @return CurlHandle|false The cURL handle or false on failure
 */
function getCurlHandle($url) {
    $ch = curl_init($url);

    // Standard Options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Handle redirects
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // SSL Verification Logic
    // Check environment variable 'DISABLE_SSL_VERIFY'
    // This allows Docker users to opt-in to insecure connections for local servers
    $disableSsl = getenv('DISABLE_SSL_VERIFY');

    if ($disableSsl === 'true' || $disableSsl === '1') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } else {
        // Explicitly enable for clarity (it's default true anyway)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    return $ch;
}
?>
