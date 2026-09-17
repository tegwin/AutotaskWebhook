<?php
session_start();
// Errors go to the server log, never the browser: this page handles API credentials.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$test_result = '';
$creds = $_SESSION['autotask_credentials'] ?? [
    'username' => '',
    'secret' => '',
    'integration_code' => '',
    'api_url' => '',
    'webhook_url' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        $_SESSION['autotask_credentials'] = [
            'username' => $_POST['username'] ?? '',
            'secret' => $_POST['secret'] ?? '',
            'integration_code' => $_POST['integration_code'] ?? '',
            'api_url' => $_POST['api_url'] ?? '',
            'webhook_url' => $_POST['webhook_url'] ?? ''
        ];
        $creds = $_SESSION['autotask_credentials'];
        $message = "Credentials saved!";
    }

    if (isset($_POST['test'])) {
        $test_url = rtrim($_POST['api_url'], '/') . '/Companies/query?search=' . urlencode(json_encode([
            'filter' => [['op' => 'eq', 'field' => 'companyType', 'value' => 1]],
            'pageSize' => 1,
            'pageNumber' => 1
        ]));

        $ch = curl_init($test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Username: ' . $_POST['username'],
            'Secret: ' . $_POST['secret'],
            'ApiIntegrationCode: ' . $_POST['integration_code'],
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

        if ($error) {
            $test_result = "<p style='color:red;'>❌ CURL Error: " . htmlspecialchars($error) . "</p>";
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $test_result = "<p style='color:green;'>✅ Credentials are working (HTTP $httpCode)</p>";
        } else {
            $test_result = "<p style='color:red;'>❌ API returned HTTP $httpCode</p>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>API Credentials</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --primary: #0066cc;
            --background: #f4f6f8;
            --card-bg: #fff;
            --border: #ddd;
            --radius: 8px;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: var(--background);
            padding: 2rem;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: var(--card-bg);
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
        }

        h2 {
            margin-bottom: 1.5rem;
            color: #333;
        }

        label {
            display: block;
            margin: 0.5rem 0 0.25rem;
            font-weight: 600;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-sizing: border-box;
        }

        .actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        button {
            background-color: var(--primary);
            color: #fff;
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 1rem;
            flex: 1;
        }

        button:hover {
            background-color: #005bb5;
        }

        .message {
            background: #e6ffed;
            color: #2f8132;
            padding: 0.75rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            border: 1px solid #a7f3d0;
        }

        .result {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Autotask API Credentials</h2>
    <?php if (!empty($message)): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="post">
        <label for="username">API Username</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($creds['username']) ?>" required>

        <label for="secret">API Secret</label>
        <input type="password" id="secret" name="secret" value="<?= htmlspecialchars($creds['secret']) ?>" required>

        <label for="integration_code">API Integration Code</label>
        <input type="text" id="integration_code" name="integration_code" value="<?= htmlspecialchars($creds['integration_code']) ?>" required>

        <label for="api_url">API Endpoint URL</label>
        <input type="text" id="api_url" name="api_url" value="<?= htmlspecialchars($creds['api_url']) ?>" required>

        <label for="webhook_url">Webhook URL</label>
        <input type="text" id="webhook_url" name="webhook_url" value="<?= htmlspecialchars($creds['webhook_url']) ?>" required>

        <div class="actions">
            <button type="submit" name="save">Save</button>
            <button type="submit" name="test">Test Credentials</button>
        </div>
    </form>

    <div class="result">
        <?= $test_result ?> <?php // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- holds only an int HTTP code and an htmlspecialchars-escaped error ?>
    </div>
</div>
</body>
</html>
