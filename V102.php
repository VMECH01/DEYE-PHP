<?php
header("Content-Type: application/json; charset=utf-8");

// ======================= STEP 1: Validate Token =======================
$reqHeaders = getallheaders();
if (!isset($reqHeaders["Token"]) || $reqHeaders["Token"] !== "Token IdeYNazjrEtOVbfPPTh8GXXhkZ") {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Unauthorized or Token missing"]);
    exit();
}

// ======================= STEP 2: Allow only POST =======================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "msg" => "Only POST method allowed"]);
    exit();
}

// ======================= STEP 3: Decode input =======================
$formData = json_decode(file_get_contents("php://input"), true);
$instID = $formData["instID"] ?? null;
$token_d = $formData["tokend"] ?? null;

if (!$instID || !$token_d) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Missing instID or tokend"]);
    exit();
}

// ======================= STEP 4: Fetch history helper =======================
function fetchHistory($stationId, $token_d, $granularity, $startAt, $endAt = null)
{
    $payload = [
        "stationId" => (int)$stationId,
        "granularity" => $granularity,
        "startAt" => $startAt
    ];
    if ($endAt) $payload["endAt"] = $endAt;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/history",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token_d
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $data = json_decode($response, true);
    return $data["stationDataItems"] ?? [];
}

// ======================= STEP 5: Parse and sum data =======================
function parseEnergyData($items)
{
    $acc = ["Pb" => 0, "Pc" => 0, "Pg" => 0, "Gc" => 0];

    foreach ($items as $item) {
        $acc["Pg"] += floatval($item["gridValue"] ?? 0);       // grid output
        $acc["Gc"] += floatval($item["purchaseValue"] ?? 0);   // imported energy
        $acc["Pc"] += floatval($item["generationValue"] ?? 0); // solar generation
        $acc["Pb"] += floatval($item["chargeValue"] ?? 0);     // chargeEnergy
    }

    foreach ($acc as $key => $value) {
        $acc[$key] = number_format((float)$value, 2, '.', '');
    }

    return $acc;
}

// ======================= STEP 6: Fetch today, yesterday, month data =======================
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
    (clone $now)->modify("today")->format("Y-m-d")
);

// $thisMonthItems = fetchHistory(
//     $instID,
//     $token_d,
//     3,
//     (clone $now)->modify("first day of this month")->format("Y-m"),
//     (clone $now)->modify("first day of next month")->format("Y-m")
// );

// $lastMonthItems = fetchHistory(
//     $instID,
//     $token_d,
//     3,
//     (clone $now)->modify("first day of last month")->format("Y-m"),
//     (clone $now)->modify("first day of this month")->format("Y-m")
// );


// ---  THIS MONTH ---
$thisMonthItems = fetchHistory(
    $instID,
    $token_d,
    3,
    (clone $now)->modify("first day of this month")->format("Y-m"),
    (clone $now)->modify("first day of this month")->format("Y-m")
);

// ---  LAST MONTH ---
$lastMonthItems = fetchHistory(
    $instID,
    $token_d,
    3,
    (clone $now)->modify("first day of last month")->format("Y-m"),
    (clone $now)->modify("first day of last month")->format("Y-m")
);

// ======================= STEP 7: Summarize =======================
$summary = [
    "today" => parseEnergyData($todayItems),
    "yesterday" => parseEnergyData($yesterdayItems),
    "thisMonth" => parseEnergyData($thisMonthItems),
    "lastMonth" => parseEnergyData($lastMonthItems)
];

// ======================= STEP 8: Output JSON =======================
$result = [
    "success" => true,
    "stationId" => $instID,
    "ttotals" => $summary["today"],
    "ytotals" => $summary["yesterday"],
    // Option A 
    // "mttotals" => $summary["thisMonth"],  // this month
    // "mltotals" => $summary["lastMonth"]   // last month
    // option => B if not getting the data 
    "thismonth" => $summary["thisMonth"],
    "lastmonth" => $summary["lastMonth"]
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit();
?>
