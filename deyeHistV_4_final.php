<?php
header("Content-Type: application/json; charset=utf-8");

// ======================= CONFIGURATION =======================
define('API_TOKEN', 'Token IdeYNazjrEtOVbfPPTh8GXXhkZ');
define('DEYE_API_URL', 'https://eu1-developer.deyecloud.com/v1.0/station/history/power');
// define('DEYE_API_URL', 'https://eu1-developer.deyecloud.com/v1.0/station/history/power');
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

    // this the line of code for error checking 
$response = curl_exec($curl);
error_log("Deye API Full Response: " . $response);
error_log("HTTP Code: " . $httpCode);

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
        // Log raw item for debugging (remove in production)
        error_log("Deye raw item: " . json_encode($item));
        
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
    // Deye provides timestamp in seconds (10 digits); keep as seconds for frontend compatibility
    return (int)$timestamp;
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
    // Parse ISO timestamps directly to DateTime
    $startDt = new DateTime($start, new DateTimeZone('UTC'));
    $endDt = new DateTime($end, new DateTimeZone('UTC'));

    $startTs = $startDt->getTimestamp();
    $endTs = $endDt->getTimestamp();

    if ($startTs >= $endTs) {
        throw new Exception("Start time must be before end time");
    }

    // Validate Deye's 12-month limit
    $diffDays = ($endTs - $startTs) / (60 * 60 * 24);
    if ($diffDays > 365.25 * 12) {
        throw new Exception("Time range must be <= 12 months (Deye API limit)");
    }

    // Fetch Deye data with Unix timestamps
    $deyeRawData = fetchDeyeHistoryPower($stationId, $accessToken, $startTs, $endTs);

    // Convert to Victron format
    $victronFormattedData = convertDeyeToVictronFormat($deyeRawData);

    // Return success response
    echo json_encode($victronFormattedData, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Deye API Error: " . $e->getMessage());
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



<!-- 
curl -X POST "http://localhost:8000/deye_api.php" \
     -H "Content-Type: application/json" \
     -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
     -d '{
       "stationId": 61195166,
       "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOlsib2F1dGgyLXJlc291cmNlIl0sInVzZXJfbmFtZSI6IjBfcHVyY2hhc2VAdm1lY2hhdHJvbmljcy5jb21fMiIsInNjb3BlIjpbImFsbCJdLCJkZXRhaWwiOnsib3JnYW5pemF0aW9uSWQiOjAsInRvcEdyb3VwSWQiOm51bGwsImdyb3VwSWQiOm51bGwsInJvbGVJZCI6LTEsInVzZXJJZCI6MTMwMTYyNDYsInZlcnNpb24iOjEsImlkZW50aWZpZXIiOiJwdXJjaGFzZUB2bWVjaGF0cm9uaWNzLmNvbSIsImlkZW50aXR5VHlwZSI6MiwibWRjIjoiZXUiLCJhcHBJZCI6IjIwMjQxMTI2MzUzNTAwMiIsIm1mYVN0YXR1cyI6bnVsbCwidGVuYW50IjoiRGV5ZSJ9LCJleHAiOjE3Njk4NDA0NTMsIm1kYyI6ImV1IiwiYXV0aG9yaXRpZXMiOlsiYWxsIl0sImp0aSI6IjQ4Njc1ZjMxLWJiYjgtNGQwOS05NjBkLTZkN2RlZDYyMGFkNyIsImNsaWVudF9pZCI6InRlc3QifQ.M_Osxpdi8hKgDkszjxzRiB4fHoGMMA2z4WKukGZRZym9v8v_t3q0Vikw3OhWTBy7-7Y6TITMw6srYMUokwjSaHMQS4br90DIGGYHMDjIpfEGmqAgWXjq8Ac9A_DN7jZEywbwAD329w4hpWUAzVeEnrsS0OsXxBzxrtJCuW831U9fGv-WIQ3uLlx01VfUk411-ly5ocTo9JETB9yxFJIDoN-1aY0CiziPX45ufbid_WTEtSk_toX50Jc-ZG9aS74GUD7DBkJjZbjqKNC3vFCBIz4V_k5WGiBDGtwGKTg5sVm72EwuKtPkZRhGYUZGbCBiO2C7p2uIqGCVZpB3fp42xw",
       "start": "2025-01-01T00:00:00Z",
       "end": "2025-01-02T00:00:00Z"
     }'
	 
curl -X POST "http://localhost:8000/deyehist4hhmm.php" \
  -H "Content-Type: application/json" \
  -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
  -d '{
    "stationId": "61195166",
    "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOlsib2F1dGgyLXJlc291cmNlIl0sInVzZXJfbmFtZSI6IjBfYnVkZGhhYmh1c2hhbkB2bWVjaGF0cm9uaWNzLmNvbV8yIiwic2NvcGUiOlsiYWxsIl0sImRldGFpbCI6eyJvcmdhbml6YXRpb25JZCI6MCwidG9wR3JvdXBJZCI6bnVsbCwiZ3JvdXBJZCI6bnVsbCwicm9sZUlkIjotMSwidXNlcklkIjoxMzQwMDQ4NSwidmVyc2lvbiI6MTAwMCwiaWRlbnRpZmllciI6ImJ1ZGRoYWJodXNoYW5Adm1lY2hhdHJvbmljcy5jb20iLCJpZGVudGl0eVR5cGUiOjIsIm1kYyI6ImV1IiwiYXBwSWQiOiIyMDI1MTAyNzY0ODYwMTAiLCJtZmFTdGF0dXMiOm51bGwsInRlbmFudCI6IkRleWUifSwiZXhwIjoxNzY5ODUzMDExLCJtZGMiOiJldSIsImF1dGhvcml0aWVzIjpbImFsbCJdLCJqdGkiOiJiODhkMzIyZi04ZjE0LTQ5NzUtOTNmYy00NzU2OGY0YWEzZWQiLCJjbGllbnRfaWQiOiJ0ZXN0In0.MALOPikYEM6DM0Wc2olYaGDxYJxpFY2p0Zf3xbZemw1pUFGJXyfZO9IlBq0WK0I7j9n-saD01LFxt930IzSn27-Dq2Au6ql7G0TrEV5WpAtWQ4wZhP2xCFyL5wkWbNlemadjiH_1nwUR75_r1sR27OPfEGJ-Q0PiurRGUI6ka1czCRxw5J1XCa2tjz5MH9bsd7w1cT5uYO4wWr_Ump9bt_Ijuv_MwXd-3XCev51_xXxJZ4_rfslUQpu8MibDp2srpzdlOFdc2g4kh046-2Ppf5cd9mRjpcAiY_JNnZu07BY2uewPI5gex2EKWSZfw1_Om0kQ1t5Rtp7Uqhj5q-VZKQ",
    "start": "2025-11-29T00:00:00Z",
    "end": "2025-11-30T00:00:00Z"
  }'
  
 
 
     curl -X POST "http://localhost:8000/deyeHistV_4_final.php" \
      -H "Content-Type: application/json" \
      -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
      -d '{
        "stationId": "61195166",
		"accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOlsib2F1dGgyLXJlc291cmNlIl0sInVzZXJfbmFtZSI6IjBfYnVkZGhhYmh1c2hhbkB2bWVjaGF0cm9uaWNzLmNvbV8yIiwic2NvcGUiOlsiYWxsIl0sImRldGFpbCI6eyJvcmdhbml6YXRpb25JZCI6MCwidG9wR3JvdXBJZCI6bnVsbCwiZ3JvdXBJZCI6bnVsbCwicm9sZUlkIjotMSwidXNlcklkIjoxMzQwMDQ4NSwidmVyc2lvbiI6MTAwMCwiaWRlbnRpZmllciI6ImJ1ZGRoYWJodXNoYW5Adm1lY2hhdHJvbmljcy5jb20iLCJpZGVudGl0eVR5cGUiOjIsIm1kYyI6ImV1IiwiYXBwSWQiOiIyMDI1MTAyNzY0ODYwMTAiLCJtZmFTdGF0dXMiOm51bGwsInRlbmFudCI6IkRleWUifSwiZXhwIjoxNzY5ODU3NTQ2LCJtZGMiOiJldSIsImF1dGhvcml0aWVzIjpbImFsbCJdLCJqdGkiOiJkMTE1MGZkZS02NmM0LTQ3YTktODI0MS00YTYzZGY0MzJiNWUiLCJjbGllbnRfaWQiOiJ0ZXN0In0.OlbggLWxYOd-sEB8sP-zkicKu_3mo_O-km29bx0xJAhZUljFNOX5K1vAyM6RwqI-eg1K41R5Yy5cAIvRhuHYmAT4fryb7rr9GWHF7YTuFsJa_wZBnHGh4p7tdNULX-KXoHSu3nWgRA62fGwLH89PaUhwZ6jR9g3ghnFbTYtV7-fIcP69DPM5Z2IdbxSJ9iJxiCyzDtK_JMST9S-b6ianzyEejVMZiKz3ea-uyJsYaddn50z-6IBZQ3QpC8FmTZfC2B0m_SdK6ezV6tESZQkLf3HUSN4ZCDqhS3JH7XCaczdvBlwfSW6wyyGtAad_Aal4KqQk2SpyadK5wVsgAl0zDw",
		"start": "2025-11-01T00:00:00Z",
		  "end": "2025-11-02T00:00:00Z"  
      }'
	  
	  curl -X POST "http://localhost:8000/deyehist4hhmm.php" \
  -H "Content-Type: application/json" \
  -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
  -d '{
    "stationId": "61336113",
    "accessToken": "eyJ...[your full token]",
    "start": "2024-11-15T00:00:00Z",
    "end": "2024-11-16T00:00:00Z"
  }'
  
  
  curl -X POST "http://localhost:8000/deye1.php" \
  -H "Content-Type: application/json" \
  -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
  -d '{
    "stationId": "61195166",
    "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOlsib2F1dGgyLXJlc291cmNlIl0sInVzZXJfbmFtZSI6IjBfYnVkZGhhYmh1c2hhbkB2bWVjaGF0cm9uaWNzLmNvbV8yIiwic2NvcGUiOlsiYWxsIl0sImRldGFpbCI6eyJvcmdhbml6YXRpb25JZCI6MCwidG9wR3JvdXBJZCI6bnVsbCwiZ3JvdXBJZCI6bnVsbCwicm9sZUlkIjotMSwidXNlcklkIjoxMzQwMDQ4NSwidmVyc2lvbiI6MTAwMCwiaWRlbnRpZmllciI6ImJ1ZGRoYWJodXNoYW5Adm1lY2hhdHJvbmljcy5jb20iLCJpZGVudGl0eVR5cGUiOjIsIm1kYyI6ImV1IiwiYXBwSWQiOiIyMDI1MTAyNzY0ODYwMTAiLCJtZmFTdGF0dXMiOm51bGwsInRlbmFudCI6IkRleWUifSwiZXhwIjoxNzY5ODU2NzA2LCJtZGMiOiJldSIsImF1dGhvcml0aWVzIjpbImFsbCJdLCJqdGkiOiJjZGRlNGEyNS0zYTIwLTRjNzQtYmNlNy1mZDgyNmJlMGJlMGUiLCJjbGllbnRfaWQiOiJ0ZXN0In0.Uk7dKX7iciCgapNvxUGKBmR5m0o8wxW5lsFesEbpy1cr6vY9m_lhMoLsBObzLM0gCkud0juIU8Q5tty2zqnF4sg9BlmU7kE2mspA-fhKv6OuEmJi45gIcb809wXEddEm1i4ZiJ6-2bKBDSsInnHe8bMO7QKiacl--u4ccjO0eN2e3gfDIyaYQPHYnb65F35fgUqDx50RT4uR767Flbt3wsEgPEIu0_-DoxGJDpD8ApaImrg7GtrjyOT_wTKnRdLmg6kp6VEP0S6aq81s72BBaA1DfqtnVqeJsK8sj80YBeDOttPAWlkzk8iPao5ynULF2fi9gbu7kg2ve0Wf6JvBcA",
    "start": "2025-11-15T00:00:00Z",
    "end": "2025-11-16T00:00:00Z"
  }'
  
curl -X POST "http://localhost:8000/deyeHistV_3.php" \
  -H "Content-Type: application/json" \
  -H "Token: Token IdeYNazjrEtOVbfPPTh8GXXhkZ" \
  -d '{
    "stationId": "61195166",
    "accessToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJhdWQiOlsib2F1dGgyLXJlc291cmNlIl0sInVzZXJfbmFtZSI6IjBfYnVkZGhhYmh1c2hhbkB2bWVjaGF0cm9uaWNzLmNvbV8yIiwic2NvcGUiOlsiYWxsIl0sImRldGFpbCI6eyJvcmdhbml6YXRpb25JZCI6MCwidG9wR3JvdXBJZCI6bnVsbCwiZ3JvdXBJZCI6bnVsbCwicm9sZUlkIjotMSwidXNlcklkIjoxMzQwMDQ4NSwidmVyc2lvbiI6MTAwMCwiaWRlbnRpZmllciI6ImJ1ZGRoYWJodXNoYW5Adm1lY2hhdHJvbmljcy5jb20iLCJpZGVudGl0eVR5cGUiOjIsIm1kYyI6ImV1IiwiYXBwSWQiOiIyMDI1MTAyNzY0ODYwMTAiLCJtZmFTdGF0dXMiOm51bGwsInRlbmFudCI6IkRleWUifSwiZXhwIjoxNzY5ODU3NTQ2LCJtZGMiOiJldSIsImF1dGhvcml0aWVzIjpbImFsbCJdLCJqdGkiOiJkMTE1MGZkZS02NmM0LTQ3YTktODI0MS00YTYzZGY0MzJiNWUiLCJjbGllbnRfaWQiOiJ0ZXN0In0.OlbggLWxYOd-sEB8sP-zkicKu_3mo_O-km29bx0xJAhZUljFNOX5K1vAyM6RwqI-eg1K41R5Yy5cAIvRhuHYmAT4fryb7rr9GWHF7YTuFsJa_wZBnHGh4p7tdNULX-KXoHSu3nWgRA62fGwLH89PaUhwZ6jR9g3ghnFbTYtV7-fIcP69DPM5Z2IdbxSJ9iJxiCyzDtK_JMST9S-b6ianzyEejVMZiKz3ea-uyJsYaddn50z-6IBZQ3QpC8FmTZfC2B0m_SdK6ezV6tESZQkLf3HUSN4ZCDqhS3JH7XCaczdvBlwfSW6wyyGtAad_Aal4KqQk2SpyadK5wVsgAl0zDw",
    "start": "20/11/2025",
    "end": "20/12/2025"
  }' -->
  
  