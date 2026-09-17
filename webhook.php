<?php
session_start();
define('CRED_FILE', __DIR__ . '/creds.json');

function loadCredentialsFromFile() {
    return file_exists(CRED_FILE) ? json_decode(file_get_contents(CRED_FILE), true) : null;
}
// Only these three webhook types exist; anything else is not echoed back
// into the page, which is where it would otherwise become script.
$allowedTypes = ['company', 'ticket', 'contact'];
$type = $_GET['type'] ?? 'company';
if (!in_array($type, $allowedTypes, true)) {
    $type = 'company';
}


if (isset($_GET['edit_creds'])) {

        $creds = loadCredentialsFromFile();
        if (!$creds) $creds = [];

        if (isset($_GET['webhookUrl'])) {
            $creds['webhookUrl'] = $_GET['webhookUrl'];
            $_SESSION['webhookUrl'] = $_GET['webhookUrl'];
        }

        if (isset($_GET['notificationEmailAddress'])) {
            $creds['notificationEmailAddress'] = $_GET['notificationEmailAddress'];
            $_SESSION['notificationEmailAddress'] = $_GET['notificationEmailAddress'];
        }

        if (isset($_GET['webhook_name'])) {
            $creds['webhook_name'] = $_GET['webhook_name'];
            $_SESSION['webhook_name'] = $_GET['webhook_name'];
        }

        if (isset($_GET['webhook_id'])) {
            $creds['webhook_id'] = $_GET['webhook_id'];
            $_SESSION['webhook_id'] = $_GET['webhook_id'];
        }

        file_put_contents(CRED_FILE, json_encode($creds, JSON_PRETTY_PRINT));

        header("Location: manage_webhooks.php?type=$type");
        exit;
}


 


$storedCreds = loadCredentialsFromFile();
if ($storedCreds) {
    $_SESSION = array_merge($_SESSION, $storedCreds);
}

$webhookTypes = [
    'company' => ['entity' => 'Companies', 'webhookEndpoint' => 'CompanyWebhooks'],
    'ticket' => ['entity' => 'Tickets', 'webhookEndpoint' => 'TicketWebhooks'],
    'contact' => ['entity' => 'Contacts', 'webhookEndpoint' => 'ContactWebhooks']
];

if (!isset($webhookTypes[$type])) {
    exit("Invalid webhook type.");
}
$config = $webhookTypes[$type];

// Credentials
$integrationCode = $_SESSION['integrationCode'] ?? '';
$apiUser = $_SESSION['apiUser'] ?? '';
$apiSecret = $_SESSION['apiSecret'] ?? '';
$baseUrl = $_SESSION['baseUrl'] ?? 'https://webservices2.autotask.net/ATServicesRest/v1.0';

$headers = [
    "ApiIntegrationCode: $integrationCode",
    "UserName: $apiUser",
    "Secret: $apiSecret",
    "Content-Type: application/json"
];

// DELETE webhook
if (isset($_GET['delete_id'])) {
    $deleteId = $_GET['delete_id'];
    $delUrl = "$baseUrl/{$config['webhookEndpoint']}/$deleteId";
    $ch = curl_init($delUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    curl_close($ch);
    header("Location: webhook.php?type=$type");
    exit;
}

// UPDATE webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $editId = (int)$_POST['edit_id'];

            $payload = json_encode([
                'id' => $editId,
                'WebhookUrl' => $_POST['webhookUrl'],
                'NotificationEmailAddress' => $_POST['notificationEmailAddress'],
                'Name' => $_POST['webhook_name'],
                'DeactivationUrl' => $_POST['webhookUrl'], // optional, but safe to include
            ]);

            $headers = [
                "Content-Type: application/json",
                "Accept: application/json",
                "ApiIntegrationCode: {$integrationCode}",
                "UserName: {$apiUser}",
                "Secret: {$apiSecret}"
            ];

            $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // echo "<pre>HTTP Code: $httpcode\nError: $error\nResponse:\n$response</pre>";
            //  header("Location: manage_webhooks.php?type=$type");
            // exit;

            // Build redirect URL
        $redirectUrl = "webhook.php?type=$type";

        // cURL error
        if ($error) {
            header("Location: $redirectUrl&error=" . urlencode("cURL Error: $error"));
            exit;
        }

        // JSON decode error
        $json = json_decode($response, true);
        if (!$json) {
            header("Location: $redirectUrl&error=" . urlencode("Invalid JSON Response: $response"));
            exit;
        }

        // API HTTP error
        if ($httpcode >= 400) {
            $msg = isset($json['errors']) ? implode(" | ", $json['errors']) : "Unknown API Error";
            header("Location: $redirectUrl&error=" . urlencode("API Error ($httpcode): $msg"));
            exit;
        }

        // SUCCESS
        header("Location: $redirectUrl&success=" . urlencode("Webhook updated successfully"));
        exit;

}


// GET webhooks via POST /query
$ch = curl_init("$baseUrl/{$config['webhookEndpoint']}/query");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["filter" => []])); // no filter = get all
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$webhooks = [];
if ($httpcode >= 200 && $httpcode < 300) {
    $webhooks = json_decode($response, true)['items'] ?? [];
} else {
    // exit("Failed to fetch webhooks: HTTP $httpcode");
}



// CREATE webhook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_webhook'])) {

     $payload = json_encode([
            "IsActive" => true,
            "isReady" => true,
            "WebhookUrl" => $_POST['webhookUrl'],
            "DeactivationUrl" => $_POST['webhookUrl'],
            "SecretKey" => "my1234",
            "NotificationEmailAddress" => $_POST['notificationEmailAddress'],
            "IsSubscribedToCreateEvents" => true,
            "isSubscribedToDeleteEvents" => true,
            "isSubscribedToUpdateEvents" => true,
            "Name" => $_POST['webhook_name'],
            "SendThresholdExceededNotification" => false
        ]);

    $createHeaders = [
        "Content-Type: application/json",
        "Accept: application/json",
        "ApiIntegrationCode: {$integrationCode}",
        "UserName: {$apiUser}",
        "Secret: {$apiSecret}"
    ];

    $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $createHeaders);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $redirectUrl = "webhook.php?type=$type";

    if ($error) {
        header("Location: $redirectUrl&error=" . urlencode("cURL Error: $error"));
        exit;
    }

    $json = json_decode($response, true);

    if (!$json) {
        header("Location: $redirectUrl&error=" . urlencode("Invalid JSON Response: $response"));
        exit;
    }

    if ($httpcode >= 400) {
        $msg = isset($json['errors']) ? implode(" | ", $json['errors']) : "Unknown API Error";
        header("Location: $redirectUrl&error=" . urlencode("API Error ($httpcode): $msg"));
        exit;
    }

    $webhookID = $json['id'] ?? ($json['itemId'] ?? null);
    $_POST['webhook_id'] = $webhookID;

    // header("Location: $redirectUrl&success=" . urlencode("Webhook created successfully"));
    // header("Location: manage_webhooks.php?type=$type");
    
         $creds = loadCredentialsFromFile();
        if (!$creds) $creds = [];

        if (isset($_POST['webhookUrl'])) {
            $creds['webhookUrl'] = $_POST['webhookUrl'];
            $_SESSION['webhookUrl'] = $_POST['webhookUrl'];
        }

        if (isset($_POST['notificationEmailAddress'])) {
            $creds['notificationEmailAddress'] = $_POST['notificationEmailAddress'];
            $_SESSION['notificationEmailAddress'] = $_POST['notificationEmailAddress'];
        }

        if (isset($_POST['webhook_name'])) {
            $creds['webhook_name'] = $_POST['webhook_name'];
            $_SESSION['webhook_name'] = $_POST['webhook_name'];
        }

        if (isset($_POST['webhook_id'])) {
            $creds['webhook_id'] = $webhookID;
            $_SESSION['webhook_id'] = $webhookID;
        }

        file_put_contents(CRED_FILE, json_encode($creds, JSON_PRETTY_PRINT));

        header("Location: manage_webhooks.php?type=$type");
        exit;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Webhooks</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">

<?php if (!empty($_GET['error'])): ?>
    <div style="padding:15px;background:#ffe2e2;border-left:5px solid #d8000c;color:#a00000;margin-bottom:15px;">
        <strong>❌ Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['success'])): ?>
    <div style="padding:15px;background:#e2ffe7;border-left:5px solid #2ecc71;color:#0d6b29;margin-bottom:15px;">
        <strong>✅ Success:</strong> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
<?php endif; ?>



    <h2>Manage <?= htmlspecialchars(ucfirst($type), ENT_QUOTES, 'UTF-8') ?> Webhooks</h2>
    <div class="mb-3">
        <a href="?type=company" class="btn btn-outline-primary <?= $type === 'company' ? 'active' : '' ?>">Company</a>
        <a href="?type=ticket" class="btn btn-outline-success <?= $type === 'ticket' ? 'active' : '' ?>">Ticket</a>
        <a href="?type=contact" class="btn btn-outline-warning <?= $type === 'contact' ? 'active' : '' ?>">Contact</a>
    </div>
    <?php if (empty($webhooks)): ?>
        <div class="alert alert-info">No webhooks found for <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>.</div>
    <?php else: ?>
        <?php foreach ($webhooks as $webhook): ?>
            <form method="post" class="border rounded p-3 mb-4">
                <input type="hidden" name="edit_id" value="<?= htmlspecialchars($webhook['id']) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Webhook URL</label>
                        <input type="text" name="webhookUrl" class="form-control" value="<?= htmlspecialchars($webhook['webhookUrl']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Notification Email</label>
                        <input type="text" name="notificationEmailAddress" class="form-control" value="<?= htmlspecialchars($webhook['notificationEmailAddress']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Name</label>
                        <input type="text" name="webhook_name" class="form-control" value="<?= htmlspecialchars($webhook['name']) ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-success btn-sm">Save Changes</button>
                    <a href="webhook.php?type=<?= urlencode($type) ?>&edit_creds=1&webhookUrl=<?= urlencode($webhook['webhookUrl']) ?>&notificationEmailAddress=<?= urlencode($webhook['notificationEmailAddress']) ?>&webhook_name=<?= urlencode($webhook['name']) ?>&webhook_id=<?= urlencode($webhook['id']) ?>" <?php // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- every value in this link is urlencoded ?>
                        class="btn btn-info btn-sm">Edit Fields</a>

                    <a href="?type=<?= urlencode($type) ?>&delete_id=<?= urlencode($webhook['id']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this webhook?')">Delete</a> <?php // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- every value in this link is urlencoded ?>
                </div>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>


    <div style="text-align:center; margin-top: 30px;">
        <a href="manage_webhooks.php?back=1" class="btn btn-primary">⬅️ Edit Credentials</a>
    </div>

    <br>
    <br>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">Create New <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?> Webhook</div>
        <div class="card-body">
            <form method="post" id="createWebhookForm">
                <input type="hidden" name="create_webhook" value="1">
            
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Webhook URL</label>
                        <input type="text" name="webhookUrl" required class="form-control" placeholder="https://example.com/webhook">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Notification Email</label>
                        <input type="email" name="notificationEmailAddress" required class="form-control" placeholder="you@example.com">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Webhook Name</label>
                        <input type="text" name="webhook_name" required class="form-control" placeholder="My Webhook">
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-primary d-flex align-items-center" id="createBtn">
                        <span id="btnText">Create Webhook</span>
                        <span id="loader" class="spinner-border spinner-border-sm ms-2 d-none"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
document.getElementById('createWebhookForm').addEventListener('submit', function () {
    const btn = document.getElementById('createBtn');
    const loader = document.getElementById('loader');
    const btnText = document.getElementById('btnText');

    // Disable button
    btn.disabled = true;

    // Show loader
    loader.classList.remove('d-none');

    // Change text (optional)
    btnText.textContent = "Creating...";
});
</script>


</body>
</html>
