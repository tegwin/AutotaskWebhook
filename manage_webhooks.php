<?php
session_start();
// Load credentials

define('CRED_FILE', __DIR__ . '/creds.json');


// test connection

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $integrationCode = $_POST['integrationCode'];
    $apiUser = $_POST['apiUser'];
    $apiSecret = $_POST['apiSecret'];
    $baseUrl = $_POST['baseUrl'];
    $notificationemailaddress = $_POST['notificationemailaddress'];
    $webhook_name = $_POST['webhook_name'];

    

     $creds = [
        'integrationCode' => $_POST['integrationCode'],
        'apiUser' => $_POST['apiUser'],
        'apiSecret' => $_POST['apiSecret'],
        'webhookUrl' => $_POST['webhookUrl'],
        'baseUrl' => $_POST['baseUrl'],
        'notificationemailaddress' => $_POST['notificationemailaddress'],
        'webhook_name' => $_POST['webhook_name'],
    ];
    $_SESSION = array_merge($_SESSION, $creds);
    saveCredentialsToFile($creds);



    $testHeaders = [
        "ApiIntegrationCode: $integrationCode",
        "UserName: $apiUser",
        "Secret: $apiSecret",
        "Content-Type: application/json"
    ];

    $ch = curl_init($baseUrl . "/Companies/entityInformation");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $testHeaders);
    $testResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $message = '';
    if ($httpCode == 200) {
        $message = "<div class='alert alert-success'>✅ Connection successful!</div>";
    } else {
        $message = "<div class='alert alert-danger'>❌ Test failed (HTTP $httpCode)<br>" . htmlspecialchars($testResponse) . "</div>";
    }

    echo $message;
    echo renderCredentialForm($integrationCode, $apiUser, $apiSecret, $_POST['webhookUrl'], $baseUrl, $notificationemailaddress, $webhook_name);
    exit;
}
// test connection




function saveCredentialsToFile($creds) {
    file_put_contents(CRED_FILE, json_encode($creds, JSON_PRETTY_PRINT));
}

function loadCredentialsFromFile() {
    if (file_exists(CRED_FILE)) {
        return json_decode(file_get_contents(CRED_FILE), true);
    }
    return null;
}

// print_r($_SESSION);
if (!isset($_SESSION['integrationCode'])) {
    $storedCreds = loadCredentialsFromFile();
    if ($storedCreds) {
        $_SESSION = array_merge($_SESSION, $storedCreds);
    }
}

$type = $_GET['type'] ?? 'company';
$webhookTypes = [
    'company' => [
        'entity'           => 'Companies',
        'webhookEndpoint'  => 'CompanyWebhooks',
        'fieldEndpoint'    => 'CompanyWebhookFields',
        'udfFieldEndpoint' => 'CompanyWebhookUdfFields',  // <-- ADD THIS
        'defaultName'      => 'Company Created Webhook'
    ],
    'ticket' => [
        'entity'           => 'Tickets',
        'webhookEndpoint'  => 'TicketWebhooks',
        'fieldEndpoint'    => 'TicketWebhookFields',
        'udfFieldEndpoint' => 'TicketWebhookUdfFields',
        'defaultName'      => 'Ticket Created Webhook'
    ],
    'contact' => [
        'entity'           => 'Contacts',
        'webhookEndpoint'  => 'ContactWebhooks',
        'fieldEndpoint'    => 'ContactWebhookFields',
        'udfFieldEndpoint' => 'ContactWebhookUdfFields',
        'defaultName'      => 'Contact Created Webhook'
    ]
];


if (!isset($webhookTypes[$type])) {
    exit("Invalid webhook type specified.");
}

$config = $webhookTypes[$type];

$integrationCode = $_SESSION['integrationCode'] ?? '';
$apiUser = $_SESSION['apiUser'] ?? '';
$apiSecret = $_SESSION['apiSecret'] ?? '';
$webhookUrl = $_SESSION['webhookUrl'] ?? '';
$baseUrl = $_SESSION['baseUrl'] ?? 'https://webservices2.autotask.net/ATServicesRest/v1.0';
$webhook_id = $_SESSION['webhook_id'] ?? '';
$notificationemailaddress = $_SESSION['notificationemailaddress'] ?? 'notify@example.com';
$webhook_name = $_SESSION['webhook_name'] ?? $config['defaultName'];

$headers = [
    "ApiIntegrationCode: $integrationCode",
    "UserName: $apiUser",
    "Secret: $apiSecret",
    "Content-Type: application/json"
];

// Handle form submissions
// if (isset($_GET['delete']) && $_GET['delete'] == 1) {
//     $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}/$webhook_id");
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
//     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
//     $response = curl_exec($ch);
//     $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     curl_close($ch);
//     if ($httpcode == 200 || $httpcode == 204) {
//         header("Location: webhook.php?type=$type&back=1");
//         exit;
//     } else {
//         echo "Failed to delete webhook: $httpcode";
//     }
//     exit;
// }


if (isset($_GET['delete_all']) && $_GET['delete_all'] == 1) {
    // Fetch all webhooks of current type
    $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!empty($data['items'])) {
        foreach ($data['items'] as $webhook) {
            $id = $webhook['id'];
            $chDel = curl_init("$baseUrl/{$config['webhookEndpoint']}/$id");
            curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($chDel, CURLOPT_HTTPHEADER, $headers);
            curl_exec($chDel);
            curl_close($chDel);
        }
        header("Location: webhook.php?type=$type&back=1");
        exit;
    } else {
        echo "No webhooks to delete.";
    }
    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_credentials'])) {
    $creds = [
        'integrationCode' => $_POST['integrationCode'],
        'apiUser' => $_POST['apiUser'],
        'apiSecret' => $_POST['apiSecret'],
        'webhookUrl' => $_POST['webhookUrl'],
        'baseUrl' => $_POST['baseUrl'],
        'notificationemailaddress' => $_POST['notificationemailaddress'],
        'webhook_name' => $_POST['webhook_name'],
    ];
    $_SESSION = array_merge($_SESSION, $creds);
    saveCredentialsToFile($creds);
}

if (isset($_GET['back']) && $_GET['back'] == 1) {
     echo renderCredentialForm(
        $_SESSION['integrationCode'] ?? '',
        $_SESSION['apiUser'] ?? '',
        $_SESSION['apiSecret'] ?? '',
        $_SESSION['webhookUrl'] ?? '', // ✅ This still holds the URL
        $_SESSION['baseUrl'] ?? '',
        $_SESSION['notificationemailaddress'] ?? '',
        $_SESSION['webhook_name'] ?? ''
    );
    exit;
}

if (!$integrationCode || !$apiUser || !$apiSecret) {
    echo renderCredentialForm($integrationCode, $apiUser, $apiSecret, $webhookUrl, $baseUrl, $notificationemailaddress, $webhook_name);
    exit;
}

// STEP 1: Check Existing Webhooks
$ch = curl_init("$baseUrl/{$config['webhookEndpoint']}/query");
$queryPayload = json_encode([
    "filter" => [["field" => "WebhookUrl", "op" => "eq", "value" => $webhookUrl]]
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $queryPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode == 200 || $httpcode == 201) {
    $respData = json_decode($response, true);
    if (!empty($respData['items'])) {
        $_SESSION['webhook_id'] = $respData['items'][0]['id'];
        // echo "Webhook already exists with ID: " . $respData['items'][0]['id'];
    } else {
        // $payload = [
        //     "IsActive" => true,
        //     "isReady" => true,
        //     "WebhookUrl" => $webhookUrl,
        //     "DeactivationUrl" => $webhookUrl,
        //     "SecretKey" => "my1234",
        //     "NotificationEmailAddress" => $notificationemailaddress,
        //     "IsSubscribedToCreateEvents" => true,
        //     "isSubscribedToDeleteEvents" => true,
        //     "isSubscribedToUpdateEvents" => true,
        //     "Name" => $webhook_name,
        //     "SendThresholdExceededNotification" => false
        // ];
        // $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}");
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_POST, true);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // $createResponse = curl_exec($ch);
        // $createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch);
        // if ($createHttpCode === 200 || $createHttpCode === 201) {
        //     $createRespData = json_decode($createResponse, true);
        //     $_SESSION['webhook_id'] = $createRespData['itemId'] ?? null;
        // } else {
        //     echo "Webhook creation failed: $createHttpCode";
        // }
    }
} else {
    exit("Webhook check failed: $httpcode");
}

// STEP 2: Fetch Entity Fields
$ch = curl_init("$baseUrl/{$config['entity']}/entityInformation/fields");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpcode != 200) {
    exit("Failed to fetch fields for entity.");
}
$fields = json_decode($response, true)['fields'] ?? [];

// STEP 3: Fetch Webhook Field Metadata
$ch = curl_init("$baseUrl/{$config['fieldEndpoint']}/entityInformation/fields");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpcode != 200) {
    exit("Failed to fetch webhook fields.");
}
$fetchfields = json_decode($response, true)['fields'] ?? [];


// STEP 3.1: Fetch UDF Webhook Field Metadata
$ch = curl_init("$baseUrl/{$config['udfFieldEndpoint']}/entityInformation/fields");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$udfResponse = curl_exec($ch);
$udfHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($udfHttpCode == 200) {
    $udfFields = json_decode($udfResponse, true)['fields'] ?? [];
} else {
    $udfFields = [];
}
// echo"<pre>";
// print_r($fetchfields);
// echo"</pre>";


// echo "<pre>";

// print_r($fetchfields[0]['picklistValues']);

// print_r($fields);
// echo "</pre>";
// get companyids




// Synchronous field saving logic removed, the page rendering starts here.

// Fetch the currently selected fields for rendering
$success = false;
$errorMessages = [];

// STEP 4: Fetch Current Webhook Fields (for pre-selection)
$selected = [];
if ($webhook_id) {
    // 1. Standard Fields
    $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}/$webhook_id/Fields");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true)['items'] ?? [];
    
    // echo "<pre>";
    // print_r($data);
    // echo "</pre>";
   
    // Convert field IDs back to names for the form
    foreach ($data as $item) {

         $fieldId = $item['fieldID'] ?? null;
        if (!$fieldId) continue;

        // Use fieldID as the mapping key
        if (!isset($selected[$fieldId])) $selected[$fieldId] = [];

        if (!empty($item['isSubscribedField'])) {
            $selected[$fieldId][] = 'subscribe';
        }

        if (!empty($item['isDisplayAlwaysField'])) {
            $selected[$fieldId][] = 'display';
        }

        // $fieldName = $item['FieldName'] ?? null;
        // if ($fieldName) {
        //     if ($item['IsSubscribedField']) {
        //         $selected[$fieldName][] = 'subscribe';
        //     }
        //     if ($item['IsDisplayAlwaysField']) {
        //         $selected[$fieldName][] = 'display';
        //     }
        // }
    }

 

    // 2. UDF Fields
    $ch = curl_init("$baseUrl/{$config['webhookEndpoint']}/$webhook_id/UdfFields");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true)['items'] ?? [];

//  echo "<pre>";
//     print_r($selected);
//     echo "</pre>";


  

    // Convert UDF IDs to the format used in the UDF table for pre-selection
    // foreach ($data as $item) {
    //     $fieldId = $item['UdfFieldId'] ?? null;
    //     if ($fieldId) {
    //         // Find the Field Name (label) from $udfList using $fieldId (value)
    //         $udfName = '';
    //         foreach ($udfList as $udf) {
    //             if ($udf['value'] == $fieldId) {
    //                 $udfName = $udf['label'];
    //                 break;
    //             }
    //         }

    //         // UDFs are identified by ID in the POST, so we use the ID as the key in $selected
    //         if ($item['IsSubscribedField']) {
    //             $selected[$fieldId][] = 'subscribe';
    //         }
    //         if ($item['IsDisplayAlwaysField']) {
    //             $selected[$fieldId][] = 'display';
    //         }
    //     }
    // }

   
        foreach ($data as $item) {
            $fieldId = $item['udfFieldID'] ?? null;

            if ($fieldId) {

                // Build selected array
                if (!empty($item['isSubscribedField'])) {
                    $selected[$fieldId][] = 'subscribe';
                }

                if (!empty($item['isDisplayAlwaysField'])) {
                    $selected[$fieldId][] = 'display';
                }
            }
        }



}


function renderCredentialForm($code, $user, $secret, $url, $baseUrl, $notificationemailaddress, $webhook_name) {
    // Every one of these lands inside a value="..." attribute, so an unescaped
    // quote would let the caller's input break out and add its own markup.
    $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $user = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
    $secret = htmlspecialchars($secret, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $baseUrl = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
    $notificationemailaddress = htmlspecialchars($notificationemailaddress, ENT_QUOTES, 'UTF-8');
    $webhook_name = htmlspecialchars($webhook_name, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Set API Credentials</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">
<h3>Enter Autotask API Credentials</h3>
<form method="post" class="row g-3" action="manage_webhooks.php?back=1">
    <div class="col-md-6">
        <label class="form-label">API User</label>
        <input type="text" name="apiUser" class="form-control" required value="{$user}">
    </div>
    <div class="col-md-6">
        <label class="form-label">API Secret</label>
        <input type="password" name="apiSecret" class="form-control" required value="{$secret}">
    </div>
     <div class="col-md-6">
        <label class="form-label">Integration Code</label>
        <input type="text" name="integrationCode" class="form-control" required value="{$code}">
    </div>
    <!-- <div class="col-md-6"> -->
        <!-- <label class="form-label">Webhook URL</label> -->
        <input type="hidden" name="webhookUrl" class="form-control" required value="{$url}">
    <!-- </div> -->
     <div class="col-md-6">
        <label class="form-label">Base URL</label>
        <input type="text" name="baseUrl" class="form-control" required value="{$baseUrl}">
    </div>

    <!-- <div class="col-md-6"> -->
        <!-- <label class="form-label">Notification Email Address</label> -->
        <input type="hidden" name="notificationemailaddress" class="form-control" required value="{$notificationemailaddress}">
    <!-- </div> -->
    <!-- <div class="col-md-6"> -->
        <!-- <label class="form-label">Webhook Name</label> -->
        <input type="hidden" name="webhook_name" class="form-control" required value="{$webhook_name}">
    <!-- </div> -->


    <div class="col-12">
        <button type="submit" name="save_credentials" class="btn btn-primary">Save & Continue</button>

        <button type="submit" name="test_connection" class="btn btn-secondary">Test Connection</button>
        
        <a href="webhook.php" class="btn btn-success" style="margin-left: 10px;">🛠️ Manage Webhooks</a>
    </div>
</form>
</body>
</html>
HTML;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select Fields</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        input.form-check-input {
            border: 1px solid #000;
        }
    </style>
</head>
<body class="container py-5">

<div class="mb-4">

  <div class="card shadow-sm mb-3" style="border-left: 4px solid #0d6efd;">
    <div class="card-body">
        <h5 class="card-title mb-3">🔔 Webhook Details</h5>

        <p class="mb-1"><strong>Webhook URL:</strong> <?= htmlspecialchars($_SESSION['webhookUrl'] ?? '') ?></p>
        <p class="mb-1"><strong>Webhook ID:</strong> <?= htmlspecialchars($webhook_id) ?></p>
        <p class="mb-0"><strong>Webhook Name:</strong> <?= htmlspecialchars($_SESSION['webhook_name'] ?? '') ?></p>
    </div>
</div>


    <!-- <h4>Select Webhook Type:</h4> -->
    <!-- <a href="?type=company" class="btn btn-outline-primary me-2 <?= $type === 'company' ? 'active' : '' ?>">Company Webhook</a> -->
    <!-- <a href="?type=ticket" class="btn btn-outline-success me-2 <?= $type === 'ticket' ? 'active' : '' ?>">Ticket Webhook</a> -->
    <!-- <a href="?type=contact" class="btn btn-outline-warning <?= $type === 'contact' ? 'active' : '' ?>">Contact Webhook</a> -->
</div>


<?php if ($success): ?>
    <div class="alert alert-success">✅ Fields successfully saved.</div>
<?php elseif (!empty($errorMessages)): ?>
    <div class="alert alert-danger">
        <?= implode('<br>', array_map('htmlspecialchars', $errorMessages)) ?>
    </div>
<?php endif; ?>

<form method="post" id="field-selection-form">
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>Field Name</th>
            <th>Trigger<br>
                <label for="select-all-subscribe"><input type="checkbox" id="select-all-subscribe" class="form-check-input"> Select All</label>
            </th>
            <th>Include<br>
                <label for="select-all-display"><input type="checkbox" id="select-all-display" class="form-check-input"> Select All</label>
            </th>
        </tr>
        </thead>
        <tbody>
        <?php 
        foreach ($fetchfields[0]['picklistValues'] as $field):
        // foreach ($fields as $field):
            $fname = $field['label'];
            $fvalue = $field['value'];
             ?>
            <tr>
                <td><?= htmlspecialchars($fname) ?></td>
                <td><input class="form-check-input subscribe-checkbox" type="checkbox" name="fields[<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>][]" value="subscribe"
                    <?= in_array('subscribe', $selected[$fvalue] ?? []) ? 'checked' : '' ?>></td>
                <td><input class="form-check-input display-checkbox" type="checkbox" name="fields[<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>][]" value="display"
                    <?= in_array('display', $selected[$fvalue] ?? []) ? 'checked' : '' ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>


    <h3>User-Defined Fields (UDF)</h3>

    <?php
        $udfList = [];

        // find the udfFieldID object
        foreach ($udfFields as $field) {
            if ($field['name'] === 'udfFieldID' && !empty($field['picklistValues'])) {
                $udfList = $field['picklistValues'];
                break;
            }
        }
    ?>

    <table class="table table-bordered">
        <thead>
        <tr>
            <th>Field Name</th>
            <th>Trigger<br>
                <label><input type="checkbox" id="select-all-subscribe-udf" class="form-check-input"> Select All</label>
            </th>
            <th>Include<br>
                <label><input type="checkbox" id="select-all-display-udf" class="form-check-input"> Select All</label>
            </th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($udfList as $udf): 
            $fname = $udf['label'];
            $fid   = $udf['value'];
        ?>
            <tr>
                <td><?= htmlspecialchars($fname) ?> </td>

                <td><input class="form-check-input subscribe-checkbox-udf"
                        type="checkbox"
                        name="fields[<?= $fid ?>][]"
                        <?= in_array('subscribe', $selected[$fid] ?? []) ? 'checked' : '' ?>
                        value="subscribe"
                    ></td>

                <td><input class="form-check-input display-checkbox-udf"
                        type="checkbox"
                        name="fields[<?= $fid ?>][]"
                        <?= in_array('display', $selected[$fid] ?? []) ? 'checked' : '' ?>
                        value="display"
                    ></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>


    <?php
        $totalFields = count($fields) + count($udfList); 
    ?>

    <div id="save-status" class="alert alert-info" style="display:none; margin-top: 15px;">
        <span id="progress-text">Saving fields... Please wait, this may take a moment.</span>
        <div class="spinner-border text-success" role="status" style="width: 1.5rem; height: 1.5rem; margin-left: 10px;">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    <div id="result-message" style="margin-top: 15px;"></div>

    <button class="btn btn-success" id="save-button">Save Field Selections</button>
    
    <a class="btn btn-secondary" href="?back=1">← Back to Credentials</a>

    <a href="webhook.php" class="btn btn-success" style="margin-left: 10px;">🛠️ Manage Webhooks</a>


</form>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('field-selection-form');
    const saveButton = document.getElementById('save-button');
    const saveStatus = document.getElementById('save-status');
    const resultMessage = document.getElementById('result-message');

    if (form) {
        form.addEventListener('submit', async function(event) {
            event.preventDefault();

            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
            saveStatus.style.display = 'flex';
            resultMessage.innerHTML = '';
            resultMessage.className = 'alert alert-light';
            resultMessage.style.whiteSpace = 'pre-line';

            const formData = new FormData(form);
            formData.append('action', 'save_fields');
            formData.append('webhook_id', '<?php echo $webhook_id; ?>');

            try {
                const response = await fetch('savefields.php?type=<?= $type ?>', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });

                // const result = await response.json();

               let resultText = await response.text();
                resultText = resultText.trim();

                // Try to extract JSON safely
                let jsonMatch = resultText.match(/\{[\s\S]*\}$/); // match last { ... }

                let plainText = resultText;
                let result = {};

                if (jsonMatch) {
                    const jsonString = jsonMatch[0];            // real JSON part
                    plainText = resultText.replace(jsonString, "").trim();  // everything before JSON

                    try {
                        result = JSON.parse(jsonString);
                    } catch (e) {
                        result = { success: false, errors: ["Invalid JSON format"] };
                    }
                } else {
                    // No JSON found — treat whole thing as text
                    result = { success: false, errors: [resultText] };
                }

                // Merge text + JSON errors
                let finalErrors = [];

                if (plainText) finalErrors.push(plainText);

                if (Array.isArray(result.errors)) {
                    finalErrors.push(...result.errors);
                }

                result.errors = finalErrors;




            // if (result.errors && Array.isArray(result.errors) && result.errors.length > 0) {

            //     resultMessage.className = "alert alert-danger"; // style for errors
            //     resultMessage.innerHTML = ""; // clear previous output

            //     result.errors.forEach(msg => {
            //         let line = msg;

            //         if (msg.includes("already associated")) {
            //             line = `⚠️ ${msg}`;
            //         }
            //         else if (msg.includes("Missing field ID")) {
            //             line = `❌ ${msg}`;
            //         }
            //         else if (msg.includes("Error for")) {
            //             line = `❌ ${msg}`;
            //         }

            //         resultMessage.innerHTML += line + "<br>";
            //     });

            // } else if (result.success) {

            //     resultMessage.className = "alert alert-success";
            //     resultMessage.innerHTML = "✅ Fields saved successfully.";

            // } else {

            //     resultMessage.className = "alert alert-warning";
            //     resultMessage.innerHTML = "⚠️ Unknown error occurred.";

            // }


               resultMessage.className = "alert alert-success";
                resultMessage.innerHTML = "✅ Fields update successfully.";


            } catch (error) {
                resultMessage.className = "alert alert-success";
                resultMessage.innerHTML = "✅ Fields update successfully.";
            }

            saveButton.disabled = false;
            saveButton.textContent = 'Save Field Selections';
            saveStatus.style.display = 'none';
        });
    }
});
</script>




<script>
document.getElementById('select-all-subscribe').addEventListener('change', function() {
    document.querySelectorAll('.subscribe-checkbox').forEach(cb => cb.checked = this.checked);
});

document.getElementById('select-all-display').addEventListener('change', function() {
    document.querySelectorAll('.display-checkbox').forEach(cb => cb.checked = this.checked);
});

    
/* UDF FIELDS */
document.getElementById('select-all-subscribe-udf').addEventListener('change', function () {
    document.querySelectorAll('.subscribe-checkbox-udf').forEach(cb => cb.checked = this.checked);
});

document.getElementById('select-all-display-udf').addEventListener('change', function () {
    document.querySelectorAll('.display-checkbox-udf').forEach(cb => cb.checked = this.checked);
});
</script>

</body>
</html>
