<?php
session_start();
// Load credentials

ob_clean();
header('Content-Type: application/json');

define('CRED_FILE', __DIR__ . '/creds.json');



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


if (!$integrationCode || !$apiUser || !$apiSecret) {
    echo renderCredentialForm($integrationCode, $apiUser, $apiSecret, $webhookUrl, $baseUrl, $notificationemailaddress, $webhook_name); // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- renderCredentialForm escapes every value it interpolates
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
        


// New AJAX Field Saving Endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_fields') {


    // IMPORTANT: Increase execution time for this specific long-running AJAX action
     ob_start(); 
    set_time_limit(0); 

    $submitted = $_POST['fields'] ?? [];
    $errorMessages = [];
    $success = true;


    // 1. STANDARD FIELDS (string keys)
        foreach ($submitted as $fieldName => $options) {

            // Skip UDF numeric IDs
            if (ctype_digit($fieldName)) continue;

            // Find field ID from metadata
            $fieldId = null;

            foreach ($fetchfields[0]['picklistValues'] as $option) {
                if (
                    isset($option['label'], $option['value']) &&
                    strtolower($option['label']) === strtolower($fieldName)
                ) {
                    $fieldId = $option['value'];
                    break;
                }
            }

            if (!$fieldId) {
                $success = false;
                $errorMessages[] = "Missing field ID for '$fieldName'";
                continue;
            }

            // Checkbox values
            $isSubscribed    = in_array("subscribe", $options) ? 1 : 0;
            $isDisplayAlways = in_array("display", $options) ? 1 : 0;


            // ======================================================
            // STEP 1: GET existing Standard Field assignments
            // ======================================================
            $listUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/Fields";

            $ch = curl_init($listUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $listResponse = curl_exec($ch);
            curl_close($ch);

            $items = json_decode($listResponse, true);

            // Autotask either returns:
            // [ {...}, {...} ]  OR  [ "items" => [ {...}, {...} ] ]
            if (isset($items['items'])) {
                $items = $items['items'];
            }

            $existingId = null;

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (isset($item['fieldID']) && $item['fieldID'] == $fieldId) {
                        $existingId = $item['id']; // real delete id
                        break;
                    }
                }
            }


            // ======================================================
            // STEP 2: DELETE existing row if present
            // ======================================================
            if ($existingId !== null) {

                $deleteUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/Fields/$existingId";

                $ch = curl_init($deleteUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $delResponse = curl_exec($ch);
                $delCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($delCode < 200 || $delCode >= 300) {
                    $success = false;
                    $errorMessages[] = "Error deleting Standard field '$fieldName': HTTP $delCode - $delResponse";
                    continue;
                }
            }


            // ======================================================
            // STEP 3: CREATE updated Standard Field row
            // ======================================================
            $payload = json_encode([
                "WebhookID"            => $webhook_id,
                "fieldID"              => $fieldId,
                "FieldName"            => $fieldName,
                "IsSubscribedField"    => $isSubscribed,
                "IsDisplayAlwaysField" => $isDisplayAlways,
            ]);

            $createUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/Fields";

            $ch = curl_init($createUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                $success = false;
                $errorMessages[] = "Error creating Standard field '$fieldName', existing '$existingId': HTTP $httpCode - $response";
            }
        }


     
        /** -----------------------------------------
         * 2️⃣ UDF FIELDS (numeric keys)
         * ----------------------------------------- */
        foreach ($submitted as $fieldId => $options) {

            if (!ctype_digit($fieldId)) continue;

            $isSubscribed = in_array("subscribe", $options) ? 1 : 0;
            $isDisplayAlways = in_array("display", $options) ? 1 : 0;


            // Build POST payload
            $payload = json_encode([
                "WebhookID" => $webhook_id,
                "UdfFieldId" => (int)$fieldId,
                "IsSubscribedField" => $isSubscribed,
                "IsDisplayAlwaysField" => $isDisplayAlways,
            ]);

            /** STEP 1: GET existing UDF assignments */
            $listUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/UdfFields";

            $ch = curl_init($listUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $listResponse = curl_exec($ch);
            curl_close($ch);

            $items = json_decode($listResponse, true)['items'] ?? [];
            $existingId = null;

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (isset($item['udfFieldID']) && $item['udfFieldID'] == $fieldId) {
                        $existingId = $item['id']; // REAL DELETE ID
                        break;
                    }
                }
            }

            /** STEP 2: DELETE the existing row if found */
            if ($existingId !== null) {

                $deleteUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/UdfFields/$existingId";

                $ch = curl_init($deleteUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $delResponse = curl_exec($ch);
                $delCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($delCode < 200 || $delCode >= 300) {
                    $success = false;
                    $errorMessages[] = "Error deleting UDF '$fieldId': HTTP $delCode - $delResponse";
                    continue;
                }
            }

            /** STEP 3: CREATE the updated UDF row */
            $createUrl = $baseUrl . "/{$config['webhookEndpoint']}/$webhook_id/UdfFields";

            $ch = curl_init($createUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                $success = false;
                $errorMessages[] = "Error creating UDF '$fieldId', existeing '$existingId': HTTP $httpCode - $response";
            }
        }




        
        // Respond in JSON to the AJAX request

        $extraOutput = trim(ob_get_clean());
        if ($extraOutput !== "") {
            $errorMessages[] = "Server Output: " . $extraOutput;
            $success = false;
        }
    
    echo json_encode(['success' => $success, 'errors' => $errorMessages]);
    exit; 
}
