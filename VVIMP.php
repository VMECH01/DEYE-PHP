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

// ======================= STEP 4: Fetch Deye data (from your working API) =======================
function fetchDeyeHistoryPower($stationId, $accessToken, $startAt, $endAt) {
    $payload = [
        "stationId" => (int)$stationId,
        "startTimestamp" => (int)$startAt,
        "endTimestamp" => (int)$endAt
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/history/power",
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
    curl_close($curl);

    if ($err) {
        throw new Exception("Curl Error: {$err}");
    }

    $data = json_decode($response, true);
    return $data["stationDataItems"] ?? [];
}

// ======================= STEP 5: Map Deye fields to Victron format =======================
function convertDeyeToVictronFormat($deyeItems) {
    
    // VICTRON STRUCTURE (exactly what your JavaScript expects)
    $victronData = [
        "enums" => [], // Deye doesn't have enum data
        "floats" => [],
        "solar" => [
            "totals" => [],
            "records" => [
                "kwh" => [], // Your JavaScript expects this
                "Pb" => [],  // Solar Production
                "Pg" => [],  // Grid Export  
                "Gc" => []   // Grid Import
            ]
        ]
    ];

    // Arrays to store time-series data
    $socRecords = [];      // State of Charge
    $currentRecords = [];  // Battery Current  
    $voltageRecords = [];  // Battery Voltage
    $acInputRecords = [];  // Grid Power
    $acLoadRecords = [];   // Consumption
    $batteryTempRecords = []; // Temperature
    
    $solarProductionRecords = []; // Solar Generation
    $gridExportRecords = [];      // Grid Export
    $gridImportRecords = [];      // Grid Import

    $totalSolar = 0;
    $totalExport = 0;
    $totalImport = 0;

    foreach ($deyeItems as $item) {
        // Get timestamp (convert if needed)
        $timestamp = $item["timeStamp"] ?? $item["timestamp"] ?? time();
        if (is_string($timestamp) && strpos($timestamp, "T") !== false) {
            $dt = new DateTime($timestamp);
            $timestamp = $dt->getTimestamp();
        } else {
            $timestamp = (int)$timestamp;
        }

        // === MAP DEYE FIELDS TO VICTRON FIELDS ===
        
        // Battery State of Charge (Deye: batterySOC → Victron: ID 51)
        $soc = floatval($item["batterySOC"] ?? 0);
        $socRecords[] = [$timestamp, $soc];
        
        // Battery Current (Deye: batteryPower → Victron: ID 49)
        // Calculate current from power: Current (A) = Power (W) / Voltage (V)
        $batteryPower = floatval($item["batteryPower"] ?? 0);
        $batteryVoltage = 48.0; // Assume 48V system for calculation
        $batteryCurrent = $batteryPower / $batteryVoltage;
        $currentRecords[] = [$timestamp, $batteryCurrent];
        
        // Battery Voltage (Victron: ID 47) - Deye doesn't provide directly
        $voltageRecords[] = [$timestamp, $batteryVoltage];
        
        // AC Input/Grid Power (Deye: gridPower → Victron: ID 17)
        $gridPower = floatval($item["gridPower"] ?? 0);
        $acInputRecords[] = [$timestamp, $gridPower];
        
        // AC Load/Consumption (Deye: consumptionPower → Victron: ID 29)
        $loadPower = floatval($item["consumptionPower"] ?? 0);
        $acLoadRecords[] = [$timestamp, $loadPower];
        
        // Battery Temperature (Victron: ID 115) - Deye might not have this
        $batteryTempRecords[] = [$timestamp, 25.0]; // Default value

        // === SOLAR DATA MAPPING ===
        
        // Solar Production (Deye: generationPower → Victron: Pb)
        $solarPower = floatval($item["generationPower"] ?? 0);
        $solarProductionRecords[] = [$timestamp, $solarPower];
        
        // Grid Export (Deye: negative gridPower → Victron: Pg)
        $gridExport = $gridPower < 0 ? abs($gridPower) : 0;
        $gridExportRecords[] = [$timestamp, $gridExport];
        
        // Grid Import (Deye: positive gridPower → Victron: Gc)  
        $gridImport = $gridPower > 0 ? $gridPower : 0;
        $gridImportRecords[] = [$timestamp, $gridImport];

        // Accumulate totals (convert Wh to kWh)
        $totalSolar += floatval($item["generationValue"] ?? 0) / 1000;
        if ($gridPower < 0) {
            $totalExport += abs($gridPower) * (5/60/1000); // Approximate conversion
        } else {
            $totalImport += $gridPower * (5/60/1000); // Approximate conversion
        }
    }

    // === BUILD VICTRON FLOATS STRUCTURE ===
    $victronData["floats"]["51"] = [
        "attributeName" => "State of Charge",
        "records" => $socRecords
    ];
    
    $victronData["floats"]["49"] = [
        "attributeName" => "Current", 
        "records" => $currentRecords
    ];
    
    $victronData["floats"]["47"] = [
        "attributeName" => "Voltage",
        "records" => $voltageRecords
    ];
    
    $victronData["floats"]["17"] = [
        "attributeName" => "AC Input L1",
        "records" => $acInputRecords
    ];
    
    $victronData["floats"]["29"] = [
        "attributeName" => "AC Output L3",
        "records" => $acLoadRecords
    ];
    
    $victronData["floats"]["115"] = [
        "attributeName" => "Battery Temperature", 
        "records" => $batteryTempRecords
    ];

    // === BUILD VICTRON SOLAR STRUCTURE ===
    $victronData["solar"]["totals"]["Pb"] = round($totalSolar, 2);
    $victronData["solar"]["totals"]["Pg"] = round($totalExport, 2);
    $victronData["solar"]["totals"]["Gc"] = round($totalImport, 2);
    
    $victronData["solar"]["records"]["Pb"] = $solarProductionRecords;
    $victronData["solar"]["records"]["Pg"] = $gridExportRecords;
    $victronData["solar"]["records"]["Gc"] = $gridImportRecords;

    return $victronData;
}

try {
    // Convert dates to timestamps
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    
    if ($startTs === false || $endTs === false) {
        throw new Exception("Invalid date format");
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