<?php
/**
 * Autotask Webhook Manager (Direct Query)
 * - Lists all CompanyWebhooks
 * - Allows deleting by ID
 */

$apiUser = "h3tijtzsjsolqtb@SONDELASANDBOX.COM";
$apiSecret = "1Wb$M*0n#rT7C2d~aZ@4@y6DY";
$integrationCode = "EVZEW4NCKCPXCU7UTV7P6HR5LWQ";
$baseUrl = "https://webservices2.autotask.net/atservicesrest/v1.0"; 
$headers = [
    "Content-Type: application/json",
    "ApiIntegrationCode: $integrationCode",
    "UserName: $apiUser",
    "Secret: $apiSecret"
];

// Delete if requested
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $ch = curl_init("$baseUrl/CompanyWebhooks/$id");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    $deleteResponse = curl_exec($ch);
    $deleteCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($deleteCode == 200 || $deleteCode == 204) {
        echo "<p style='color:green;'>? Webhook ID " . htmlspecialchars($id) . " deleted successfully.</p>";
    } else {
        echo "<p style='color:red;'>? Failed to delete webhook ID " . htmlspecialchars($id) . " (HTTP " . htmlspecialchars($deleteCode) . "): " . htmlspecialchars($deleteResponse) . "</p>"; // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- this is an echo, not SQL; each value is escaped
    }
}

// Fetch all webhooks
$queryPayload = [ "Filter" => [] ];

$ch = curl_init("$baseUrl/CompanyWebhooks/query");
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryPayload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode != 200) {
    die("? Failed to fetch webhooks. HTTP $httpCode: $response");
}

$data = json_decode($response, true);
$webhooks = $data['items'] ?? [];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Autotask Webhook Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #333; color: #fff; }
        tr:nth-child(even) { background: #f9f9f9; }
        a.delete { color: red; text-decoration: none; font-weight: bold; }
        a.delete:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>?? Autotask Webhook Manager</h1>

    <?php if (empty($webhooks)): ?>
        <p>No webhooks found.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>URL</th>
                <th>Active</th>
                <th>Action</th>
            </tr>
            <?php foreach ($webhooks as $wh): ?>
                <tr>
                    <td><?= htmlspecialchars($wh['id']) ?></td>
                    <td><?= htmlspecialchars($wh['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($wh['webhookUrl'] ?? '') ?></td>
                    <td><?= !empty($wh['isActive']) ? "?" : "?" ?></td>
                    <td><a class="delete" href="?delete=<?= $wh['id'] ?>" onclick="return confirm('Delete webhook ID <?= $wh['id'] ?>?')">? Delete</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
