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
$stationId = $formData["stationId"] ?? null;
$accessToken = $formData["accessToken"] ?? null;
$start = $formData["start"] ?? null;
$end = $formData["end"] ?? null;

if (!$stationId || !$accessToken || !$start || !$end) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Missing stationId, accessToken, start, or end"]);
    exit();
}

// ======================= STEP 4: Fetch Deye data =======================
function fetchDeyeHistoryPower($stationId, $accessToken, $startAt, $endAt) {
    $payload = [
        "stationId" => (int)$stationId,
        "startTimestamp" => (int)$startAt,
        "endTimestamp" => (int)$endAt
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eu1-developer.deyecloud.com/station/history/power", // Removed /v1.0
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $accessToken
        ]
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        throw new Exception("Curl Error: {$err}");
    }

    $data = json_decode($response, true);
    
    if ($httpCode !== 200) {
        throw new Exception("API Error: " . ($data["message"] ?? "Unknown error"));
    }

    return $data["stationDataItems"] ?? [];
}

// ======================= STEP 5: Map Deye fields to Victron format =======================
function convertDeyeToVictronFormat($deyeItems) {
    
    $victronData = [
        "enums" => [],
        "floats" => [],
        "solar" => [
            "totals" => ["Pb" => 0, "Pg" => 0, "Gc" => 0, "Pc" => 0],
            "records" => [
                "kwh" => [],
                "Pb" => [],
                "Pg" => [],
                "Gc" => [],
                "Pc" => []
            ]
        ]
    ];

    $socRecords = [];
    $currentRecords = [];
    $voltageRecords = [];
    $acInputRecords = [];
    $acLoadRecords = [];
    $batteryTempRecords = [];

    $totalPb = 0;
    $totalPg = 0;
    $totalGc = 0;
    $totalPc = 0;

    foreach ($deyeItems as $item) {
        // Convert timestamp to Unix timestamp
        $timestamp = $item["timeStamp"] ?? time();
        if (is_string($timestamp)) {
            $dt = new DateTime($timestamp);
            $timestamp = $dt->getTimestamp();
        }

        // ======================= FLOATS DATA =======================
        
        // SOC (State of Charge)
        $soc = floatval($item["batterySOC"] ?? 0);
        $socRecords[] = [$timestamp, $soc];
        
        // Battery Current = (chargePower - dischargePower) / nominal voltage
        $nominalVoltage = 48;
        $chargePower = floatval($item["chargePower"] ?? 0);
        $dischargePower = floatval($item["dischargePower"] ?? 0);
        $battCurrent = ($chargePower - $dischargePower) / $nominalVoltage;
        $currentRecords[] = [$timestamp, $battCurrent];
        
        // Battery Voltage (fixed)
        $voltageRecords[] = [$timestamp, $nominalVoltage];
        
        // AC Input (Grid Power)
        $gridPower = floatval($item["gridPower"] ?? 0);
        $acInputRecords[] = [$timestamp, $gridPower];
        
        // AC Load (Consumption)
        $loadPower = floatval($item["consumptionPower"] ?? 0);
        $acLoadRecords[] = [$timestamp, $loadPower];
        
        // Battery Temperature (default)
        $batteryTempRecords[] = [$timestamp, 25.0];

        // ======================= SOLAR DATA (kWh) =======================
        // Deye returns kWh already - DO NOT divide by 1000
        
        $Pg = floatval($item["gridValue"] ?? 0);       // export to grid
        $Gc = floatval($item["purchaseValue"] ?? 0);   // import from grid  
        $Pc = floatval($item["generationValue"] ?? 0); // solar generation
        $Pb = floatval($item["chargeValue"] ?? 0);     // battery charge energy

        $victronData["solar"]["records"]["Pg"][] = [$timestamp, $Pg];
        $victronData["solar"]["records"]["Gc"][] = [$timestamp, $Gc];
        $victronData["solar"]["records"]["Pc"][] = [$timestamp, $Pc];
        $victronData["solar"]["records"]["Pb"][] = [$timestamp, $Pb];

        $totalPg += $Pg;
        $totalGc += $Gc;
        $totalPc += $Pc;
        $totalPb += $Pb;
    }

    // ======================= ASSIGN VICTRON FLOAT STRUCTURE =======================
    
    $victronData["floats"]["51"] = [
        "attributeName" => "State of Charge",
        "records" => $socRecords
    ];
    
    $victronData["floats"]["49"] = [
        "attributeName" => "Battery Current", 
        "records" => $currentRecords
    ];
    
    $victronData["floats"]["47"] = [
        "attributeName" => "Battery Voltage",
        "records" => $voltageRecords
    ];
    
    $victronData["floats"]["17"] = [
        "attributeName" => "Grid Power Input",
        "records" => $acInputRecords
    ];
    
    $victronData["floats"]["29"] = [
        "attributeName" => "Load Power",
        "records" => $acLoadRecords
    ];
    
    $victronData["floats"]["115"] = [
        "attributeName" => "Battery Temperature", 
        "records" => $batteryTempRecords
    ];

    // ======================= TOTALS (kWh) =======================
    $victronData["solar"]["totals"] = [
        "Pb" => round($totalPb, 2),
        "Pg" => round($totalPg, 2),
        "Gc" => round($totalGc, 2),
        "Pc" => round($totalPc, 2)
    ];

    return $victronData;
}

try {
    // Convert dates to timestamps
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    
    if ($startTs === false || $endTs === false) {
        throw new Exception("Invalid date format for start or end");
    }

    // Fetch raw Deye data
    $deyeRawData = fetchDeyeHistoryPower($stationId, $accessToken, $startTs, $endTs);
    
    // Convert to Victron format
    $victronFormattedData = convertDeyeToVictronFormat($deyeRawData);
    
    // Return in EXACT Victron format
    echo json_encode($victronFormattedData, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => "Error: " . $e->getMessage()]);
}

exit();
?>