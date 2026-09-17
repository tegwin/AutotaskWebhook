<?php
session_start();
// Errors go to the server log, never the browser: this page handles API credentials.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Check session credentials
$creds = $_SESSION['autotask_credentials'] ?? null;

if (!$creds || empty($creds['username']) || empty($creds['secret']) || empty($creds['integration_code']) || empty($creds['api_url'])) {
    die("Error: Credentials are missing. Please set them in <a href='credentials.php'>credentials.php</a>.");
}

$test_url = rtrim($creds['api_url'], '/') . '/Companies/query?search=' . urlencode(json_encode([
    'filter' => [['op' => 'eq', 'field' => 'companyType', 'value' => 1]],
    'pageSize' => 1,
    'pageNumber' => 1
]));

$ch = curl_init($test_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Username: ' . $creds['username'],
    'Secret: ' . $creds['secret'],
    'ApiIntegrationCode: ' . $creds['integration_code'],
    'Content-Type: application/json'
]);
// Credentials travel in these headers, so the certificate must be verified.
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<h2>Autotask API Credential Test</h2>";

if ($error) {
    echo "<p style='color:red;'>❌ CURL Error: " . htmlspecialchars($error) . "</p>";
} elseif ($httpCode >= 200 && $httpCode < 300) {
    echo "<p style='color:green;'>✅ Credentials are working (HTTP $httpCode)</p>";
    echo "<pre>" . htmlspecialchars(substr($response, 0, 1000)) . "</pre>";
} else {
    echo "<p style='color:red;'>❌ API returned HTTP $httpCode</p>";
    echo "<pre>" . htmlspecialchars(substr($response, 0, 1000)) . "</pre>";
}
?>
