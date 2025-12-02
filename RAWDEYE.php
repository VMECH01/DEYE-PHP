
<!-- This is file of api copy of the Express code -->
<?php
header("Content-Type: application/json; charset=utf-8");

// ======================= STEP 1: Validate Token =======================
$reqHeaders = getallheaders();
if (!isset($reqHeaders["Token"]) || $reqHeaders["Token"] !== "Token IdeYNazjrEtOVbfPPTh8GXXhkZ") {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Unauthorized or Token missing"]);
    exit();
}

// ======================= STEP 2: Allow only GET =======================
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Only GET method allowed"]);
    exit();
}

// ======================= STEP 3: Get query params =======================
$stationId = $_GET["stationId"] ?? null;
$startDate = $_GET["startDate"] ?? null;
$endDate = $_GET["endDate"] ?? null;

if (!$stationId || !$startDate || !$endDate) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "stationId, startDate and endDate are required"]);
    exit();
}

// ======================= STEP 4: Convert dates to Unix timestamps =======================
try {
    $startTimestamp = DateTime::createFromFormat('d/M/Y', $startDate)->getTimestamp();
    $endTimestamp = DateTime::createFromFormat('d/M/Y', $endDate)->getTimestamp();
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Invalid date format. Use DD/MMM/YYYY"]);
    exit();
}

// ======================= STEP 5: Fetch history data =======================
function fetchPowerHistory($stationId, $startTimestamp, $endTimestamp, $token)
{
    $payload = [
        "stationId" => (int)$stationId,
        "startTimestamp" => $startTimestamp,
        "endTimestamp" => $endTimestamp
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/history/power",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    $data = json_decode($response, true);

    return $data["stationDataItems"] ?? [];
}

// ======================= STEP 6: Process the items =======================
$token_d = "YOUR_DEYE_BEARER_TOKEN_HERE"; // Replace with actual token
$items = fetchPowerHistory($stationId, $startTimestamp, $endTimestamp, $token_d);

$dataForGraph = [];

foreach ($items as $item) {
    // Detect if timestamp is ISO or numeric
    if (isset($item['timeStamp']) && strpos($item['timeStamp'], 'T') !== false) {
        $dt = new DateTime($item['timeStamp']);
        $tsUnix = $dt->getTimestamp();
    } else {
        $tsUnix = intval($item['timeStamp']);
        $dt = (new DateTime())->setTimestamp($tsUnix);
    }

    $dataForGraph[] = [
        'timestamp' => $tsUnix,
        'iso' => $dt->format(DateTime::ISO8601),
        'local' => $dt->format('Y-m-d H:i:s'),
        'batteryPower' => isset($item['batteryPower']) ? floatval($item['batteryPower']) : 0,
        'batterySOC' => isset($item['batterySOC']) ? floatval($item['batterySOC']) : 0,
        'chargePower' => isset($item['chargePower']) ? floatval($item['chargePower']) : 0,
        'chargeValue' => isset($item['chargeValue']) ? floatval($item['chargeValue']) : 0,
        'dischargePower' => isset($item['dischargePower']) ? floatval($item['dischargePower']) : 0,
        'dischargeValue' => isset($item['dischargeValue']) ? floatval($item['dischargeValue']) : 0,
        'consumptionPower' => isset($item['consumptionPower']) ? floatval($item['consumptionPower']) : 0,
        'consumptionValue' => isset($item['consumptionValue']) ? floatval($item['consumptionValue']) : 0,
        'generationPower' => isset($item['generationPower']) ? floatval($item['generationPower']) : 0,
        'generationValue' => isset($item['generationValue']) ? floatval($item['generationValue']) : 0,
        'gridPower' => isset($item['gridPower']) ? floatval($item['gridPower']) : 0,
        'gridValue' => isset($item['gridValue']) ? floatval($item['gridValue']) : 0,
        'purchasePower' => isset($item['purchasePower']) ? floatval($item['purchasePower']) : 0,
        'purchaseValue' => isset($item['purchaseValue']) ? floatval($item['purchaseValue']) : 0,
        'irradiate' => isset($item['irradiate']) ? floatval($item['irradiate']) : 0,
        'irradiateIntensity' => isset($item['irradiateIntensity']) ? floatval($item['irradiateIntensity']) : 0,
        'pr' => isset($item['pr']) ? floatval($item['pr']) : 0,
        'theoreticalGeneration' => isset($item['theoreticalGeneration']) ? floatval($item['theoreticalGeneration']) : 0,
        'wirePower' => isset($item['wirePower']) ? floatval($item['wirePower']) : 0,
        'fullPowerHours' => isset($item['fullPowerHours']) ? floatval($item['fullPowerHours']) : 0,
        'chargeRatio' => isset($item['chargeRatio']) ? floatval($item['chargeRatio']) : 0,
        'consumptionDischargeRatio' => isset($item['consumptionDischargeRatio']) ? floatval($item['consumptionDischargeRatio']) : 0,
        'consumptionRatio' => isset($item['consumptionRatio']) ? floatval($item['consumptionRatio']) : 0,
        'generationRatio' => isset($item['generationRatio']) ? floatval($item['generationRatio']) : 0,
        'gridRatio' => isset($item['gridRatio']) ? floatval($item['gridRatio']) : 0,
        'purchaseRatio' => isset($item['purchaseRatio']) ? floatval($item['purchaseRatio']) : 0,
        'cpr' => isset($item['cpr']) ? floatval($item['cpr']) : 0
    ];
}

// ======================= STEP 7: Output =======================
$result = [
    "success" => true,
    "stationId" => intval($stationId),
    "startDate" => $startDate,
    "endDate" => $endDate,
    "totalPoints" => count($dataForGraph),
    "data" => $dataForGraph
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit();
