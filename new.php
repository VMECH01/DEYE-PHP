<?php
$reqHeaders = getallheaders();
$token = null;

// Step 1 =>  Validate custom API token
if (!isset($reqHeaders["Token"])) {
    header("content-type: application/json;charset=utf-8", true, 401);
    echo json_encode([
        "success" => false,
        "msg" => "Token missing"
    ]);
    exit();
}

$token = $reqHeaders["Token"];
if ($token != "Token IdeYNazjrEtOVbfPPTh8GXXhkZ") {
    header("content-type: application/json;charset=utf-8", true, 401);
    echo json_encode([
        "success" => false,
        "msg" => "Token invalid"
    ]);
    exit();
}

// Step 2 => Allow only POST requests
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("content-type: application/json;charset=utf-8", true, 405);
    echo json_encode(["success" => false, "msg" => "METHOD NOT ALLOWED"]);
    exit();
}

// Step 3 => Decode request body
$formData = json_decode(file_get_contents("php://input", true), true);
$userID = $formData["userID"] ?? null;
$instID = $formData["instID"] ?? null;
$token_d = $formData["tokend"] ?? null;

if (!$userID || !$instID ) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Missing parameters"]);
    exit();
}

// Step 4 => Helper function to fetch history data
function fetchHistory($stationId, $token_d, $granularity, $startAt, $endAt = null)
{
    $payload = [
        "stationId" => (int)$stationId,
        "granularity" => $granularity,
        "startAt" => $startAt
    ];
    if ($endAt) {
        $payload["endAt"] = $endAt;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/history",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token_d,
            'Cookie: cookiesession1=678A3E0EC13FC723FC53C6964C119F37'
        ],
    ]);
    $response = curl_exec($curl);
    curl_close($curl);

    $data = json_decode($response, true);
    return $data["stationDataItems"] ?? [];
}

// Step 5: Helper function to parse and sum energy data
function parseEnergyData($items)
{
    $acc = [
        "solarProduction" => 0,
        "exportToGrid" => 0,
        "importFromGrid" => 0
        // "consumption" => 0,
        // "chargeEnergy" => 0,
        // "dischargeEnergy" => 0
    ];

    foreach ($items as $item) {
        $acc["solarProduction"] += floatval($item["generationValue"] ?? 0);
        $acc["exportToGrid"] += floatval($item["gridValue"] ?? 0);
        $acc["importFromGrid"] += floatval($item["purchaseValue"] ?? 0);
        // $acc["consumption"] += floatval($item["consumptionValue"] ?? 0);
        // $acc["chargeEnergy"] += floatval($item["chargeValue"] ?? 0);
        // $acc["dischargeEnergy"] += floatval($item["dischargeValue"] ?? 0);
    }

    return $acc;
}

// Step 6: Fetch data for all time periods
$now = new DateTime();

$todayItems = fetchHistory(
    $instID,
    $token_d,
    2,
    (clone $now)->setTime(0, 0, 0)->format("Y-m-d"),
    (clone $now)->modify("+1 day")->format("Y-m-d")
);

$yesterdayItems = fetchHistory(
    $instID,
    $token_d,
    2,
    (clone $now)->modify("-1 day")->setTime(0, 0, 0)->format("Y-m-d"),
    (clone $now)->setTime(0, 0, 0)->format("Y-m-d")
);

$thisMonthItems = fetchHistory(
    $instID,
    $token_d,
    3,
    (clone $now)->modify("first day of this month")->format("Y-m"),
    (clone $now)->modify("first day of next month")->format("Y-m")
);

$lastMonthItems = fetchHistory(
    $instID,
    $token_d,
    3,
    (clone $now)->modify("first day of last month")->format("Y-m"),
    (clone $now)->modify("first day of this month")->format("Y-m")
);

$summary = [
    "today" => parseEnergyData($todayItems),
    "yesterday" => parseEnergyData($yesterdayItems),
    "thisMonth" => parseEnergyData($thisMonthItems),
    "lastMonth" => parseEnergyData($lastMonthItems)
];

// Step 7 => Fetch latest data
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/latest",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode(["stationId" => (int)$instID]),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token_d,
        "Cookie: cookiesession1=678A3E0EC13FC723FC53C6964C119F37"
    ],
]);
$latestResponse = curl_exec($curl);
curl_close($curl);

$latestData = json_decode($latestResponse, true);

// Step 8 => Combine and output response
$result = [
    "success" => true,
    "stationId" => $instID,
    "latest" => $latestData,
    "summary" => $summary
];

header("content-type: application/json;charset=utf-8");
echo json_encode($result, JSON_PRETTY_PRINT);
exit();
?>
