 <?php
header("Content-Type: application/json; charset=utf-8");

// ======================= CONFIGURATION =======================
define('API_TOKEN', 'Token IdeYNazjrEtOVbfPPTh8GXXhkZ');
define('DEYE_API_URL', 'https://eu1-developer.deyecloud.com/station/history/power');

// ======================= STEP 1: Validate Token =======================
$reqHeaders = getallheaders();
if (!isset($reqHeaders["Token"]) || $reqHeaders["Token"] !== API_TOKEN) {
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

// ======================= STEP 3: Decode and Validate Input =======================
$formData = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Invalid JSON input"]);
    exit();
}

$stationId = $formData["stationId"] ?? null;
$accessToken = $formData["accessToken"] ?? null;
$start = $formData["start"] ?? null;
$end = $formData["end"] ?? null;

if (!$stationId || !$accessToken || !$start || !$end) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Missing required fields: stationId, accessToken, start, or end"]);
    exit();
}

// ======================= STEP 4: Fetch Deye Data =======================
function fetchDeyeHistoryPower($stationId, $accessToken, $startAt, $endAt) {
    $payload = [
        "stationId" => (int)$stationId,
        "startTimestamp" => (int)$startAt,
        "endTimestamp" => (int)$endAt
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => DEYE_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $accessToken,
            "User-Agent: Victron-Integration/1.0"
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
        $errorMsg = $data["message"] ?? $data["msg"] ?? "Unknown API error";
        throw new Exception("Deye API Error ({$httpCode}): {$errorMsg}");
    }

    return $data["stationDataItems"] ?? [];
}

// ======================= STEP 5: Enhanced Data Conversion =======================
function convertDeyeToVictronFormat($deyeItems) {
    if (empty($deyeItems)) {
        return getEmptyVictronResponse();
    }

    $victronData = [
        "enums" => getEmptyEnums(),
        "floats" => [],
        "solar" => [
            "success" => true,
            "totals" => ["Pb" => 0, "Pg" => 0, "Gc" => 0, "Pc" => 0, "Bg" => 0, "Bc" => 0],
            "records" => [
                "kwh" => [],
                "Pb" => [],  // Production to Battery
                "Pg" => [],  // Production to Grid  
                "Gc" => [],  // Grid Consumption
                "Pc" => [],  // Production Consumption
                "Bg" => [],  // Battery to Grid
                "Bc" => [],  // Battery Consumption
                "Gb" => []   // Grid to Battery
            ]
        ]
    ];

    // Initialize arrays for all data types
    $dataArrays = initializeDataArrays();
    
    $totals = [
        'Pb' => 0, 'Pg' => 0, 'Gc' => 0, 'Pc' => 0, 
        'Bg' => 0, 'Bc' => 0, 'Gb' => 0, 'kwh' => 0
    ];

    foreach ($deyeItems as $item) {
        $timestamp = normalizeTimestamp($item["timeStamp"] ?? time());
        
        // Extract and validate values
        $values = extractDeyeValues($item);
        
        // Process float data
        processFloatData($dataArrays, $timestamp, $values);
        
        // Process solar data  
        processSolarData($dataArrays, $victronData, $timestamp, $values, $totals);
    }

    // Assign all data to Victron format
    $victronData["floats"] = createFloatStructure($dataArrays);
    $victronData["solar"]["totals"] = calculateFinalTotals($totals);
    
    return $victronData;
}

// ======================= HELPER FUNCTIONS =======================

function getEmptyVictronResponse() {
    return [
        "enums" => getEmptyEnums(),
        "floats" => [],
        "solar" => [
            "success" => true,
            "totals" => ["Pb" => 0, "Pg" => 0, "Gc" => 0, "Pc" => 0, "Bg" => 0, "Bc" => 0, "Gb" => 0],
            "records" => [
                "kwh" => [], "Pb" => [], "Pg" => [], "Gc" => [], 
                "Pc" => [], "Bg" => [], "Bc" => [], "Gb" => []
            ]
        ]
    ];
}

function getEmptyEnums() {
    return [
        "43" => ["attributeName" => "Low Battery", "occurrences" => []],
        "44" => ["attributeName" => "Overload", "occurrences" => []],
        "119" => ["attributeName" => "Low Voltage", "occurrences" => []],
        "120" => ["attributeName" => "High Voltage", "occurrences" => []],
        "124" => ["attributeName" => "Low Battery Temperature", "occurrences" => []],
        "125" => ["attributeName" => "High Battery Temperature", "occurrences" => []],
        "287" => ["attributeName" => "High Charge Current", "occurrences" => []],
        "288" => ["attributeName" => "High Discharge Current", "occurrences" => []],
        "346" => ["attributeName" => "Overload L1", "occurrences" => []],
        "350" => ["attributeName" => "Overload L2", "occurrences" => []],
        "354" => ["attributeName" => "Overload L3", "occurrences" => []],
        "519" => ["attributeName" => "Phase Rotation", "occurrences" => []],
        "559" => ["attributeName" => "Power Cut", "occurrences" => []]
    ];
}

function initializeDataArrays() {
    return [
        'soc' => [], 'current' => [], 'voltage' => [], 
        'acInput' => [], 'acLoad' => [], 'temp' => []
    ];
}

function normalizeTimestamp($timestamp) {
    if (is_string($timestamp)) {
        $dt = new DateTime($timestamp);
        return $dt->getTimestamp() * 1000; // Convert to milliseconds for Victron
    }
    return $timestamp * 1000; // Assume seconds, convert to milliseconds
}

function extractDeyeValues($item) {
    return [
        'soc' => floatval($item["batterySOC"] ?? 0),
        'chargePower' => floatval($item["chargePower"] ?? 0),
        'dischargePower' => floatval($item["dischargePower"] ?? 0),
        'gridPower' => floatval($item["gridPower"] ?? 0),
        'loadPower' => floatval($item["consumptionPower"] ?? 0),
        'generation' => floatval($item["generationValue"] ?? 0),
        'gridExport' => floatval($item["gridValue"] ?? 0),
        'gridImport' => floatval($item["purchaseValue"] ?? 0),
        'batteryCharge' => floatval($item["chargeValue"] ?? 0),
        'batteryDischarge' => floatval($item["dischargeValue"] ?? 0)
    ];
}

function processFloatData(&$dataArrays, $timestamp, $values) {
    $nominalVoltage = 48.0;
    
    // SOC
    $dataArrays['soc'][] = [$timestamp, $values['soc']];
    
    // Battery Current (A) = (Charge - Discharge) / Voltage
    $battCurrent = ($values['chargePower'] - $values['dischargePower']) / $nominalVoltage;
    $dataArrays['current'][] = [$timestamp, round($battCurrent, 3)];
    
    // Battery Voltage (V) - fixed nominal
    $dataArrays['voltage'][] = [$timestamp, $nominalVoltage];
    
    // AC Input (Grid Power in kW)
    $dataArrays['acInput'][] = [$timestamp, round($values['gridPower'] / 1000, 3)]; // W to kW
    
    // AC Load (Consumption in kW)  
    $dataArrays['acLoad'][] = [$timestamp, round($values['loadPower'] / 1000, 3)]; // W to kW
    
    // Battery Temperature (°C) - estimated
    $temp = estimateBatteryTemperature($values);
    $dataArrays['temp'][] = [$timestamp, $temp];
}

function processSolarData(&$dataArrays, &$victronData, $timestamp, $values, &$totals) {
    // Deye provides values in kWh - use as is
    $Pb = $values['batteryCharge'];      // Production to Battery
    $Pg = $values['gridExport'];         // Production to Grid
    $Gc = $values['gridImport'];         // Grid Consumption
    $Pc = $values['generation'];         // Total Production
    $Bc = $values['batteryDischarge'];   // Battery Consumption
    
    // Calculate derived values
    $kwh = $Pc; // Total energy
    $Bg = max(0, $Bc - $Gc); // Battery to Grid (excess battery discharge)
    $Gb = max(0, $Gc - $Bc); // Grid to Battery (excess grid import)
    
    // Add to records
    $victronData["solar"]["records"]["Pb"][] = [$timestamp, round($Pb, 6)];
    $victronData["solar"]["records"]["Pg"][] = [$timestamp, round($Pg, 6)];
    $victronData["solar"]["records"]["Gc"][] = [$timestamp, round($Gc, 6)];
    $victronData["solar"]["records"]["Pc"][] = [$timestamp, round($Pc, 6)];
    $victronData["solar"]["records"]["Bc"][] = [$timestamp, round($Bc, 6)];
    $victronData["solar"]["records"]["Bg"][] = [$timestamp, round($Bg, 6)];
    $victronData["solar"]["records"]["Gb"][] = [$timestamp, round($Gb, 6)];
    $victronData["solar"]["records"]["kwh"][] = [$timestamp, round($kwh, 6)];
    
    // Accumulate totals
    $totals['Pb'] += $Pb;
    $totals['Pg'] += $Pg;
    $totals['Gc'] += $Gc;
    $totals['Pc'] += $Pc;
    $totals['Bc'] += $Bc;
    $totals['Bg'] += $Bg;
    $totals['Gb'] += $Gb;
    $totals['kwh'] += $kwh;
}

function estimateBatteryTemperature($values) {
    // Simple temperature estimation based on charge/discharge activity
    $baseTemp = 25.0;
    $activity = abs($values['chargePower'] - $values['dischargePower']) / 1000; // kW
    
    // Increase temp based on activity (0-10°C range)
    $tempIncrease = min(10.0, $activity * 2);
    
    return round($baseTemp + $tempIncrease, 1);
}

function createFloatStructure($dataArrays) {
    return [
        "51" => ["attributeName" => "State of Charge", "records" => $dataArrays['soc']],
        "49" => ["attributeName" => "Battery Current", "records" => $dataArrays['current']],
        "47" => ["attributeName" => "Battery Voltage", "records" => $dataArrays['voltage']],
        "17" => ["attributeName" => "AC Input L1", "records" => $dataArrays['acInput']],
        "29" => ["attributeName" => "AC Load L1", "records" => $dataArrays['acLoad']],
        "115" => ["attributeName" => "Battery Temperature", "records" => $dataArrays['temp']]
    ];
}

function calculateFinalTotals($totals) {
    return [
        "Pb" => round($totals['Pb'], 2),
        "Pg" => round($totals['Pg'], 2), 
        "Gc" => round($totals['Gc'], 2),
        "Pc" => round($totals['Pc'], 2),
        "Bc" => round($totals['Bc'], 2),
        "Bg" => round($totals['Bg'], 2),
        "Gb" => round($totals['Gb'], 2)
    ];
}

// ======================= MAIN EXECUTION =======================
try {
    // FIXED: Parse dates to match input format DD/MM/YYYY:HH:mm (matching JS logic)
    $startDt = DateTime::createFromFormat("d/m/Y:H:i", $start);
    $endDt = DateTime::createFromFormat("d/m/Y:H:i", $end);

    if (!$startDt || !$endDt) {
        throw new Exception("Invalid date format. Use DD/MM/YYYY:HH:mm (e.g., 20/11/2025:12:00)");
    }

    $startTs = $startDt->getTimestamp();
    $endTs = $endDt->getTimestamp();

    if ($startTs >= $endTs) {
        throw new Exception("Start time must be before end time");
    }

    // Fetch Deye data
    $deyeRawData = fetchDeyeHistoryPower($stationId, $accessToken, $startTs, $endTs);
    
    // Convert to Victron format
    $victronFormattedData = convertDeyeToVictronFormat($deyeRawData);
    
    // Return success response
    echo json_encode($victronFormattedData, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "msg" => $e->getMessage(),
        "enums" => getEmptyEnums(),
        "floats" => [],
        "solar" => ["success" => false, "totals" => [], "records" => []]
    ]);
}

exit();
?>
