<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


// this allow only POST method 
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class DeyeCloudService
{
    private $client;
    private $baseUrl;
    private $appId;
    private $appSecret;
    private $email;
    private $password;
    private $accessToken;
    private $tokenExpiry;

    public function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        $this->baseUrl = $_ENV['DEYE_BASE_URL'] ?? '';
        $this->appId = $_ENV['DEYE_APP_ID'] ?? '';
        $this->appSecret = $_ENV['DEYE_APP_SECRET'] ?? '';
        $this->email = $_ENV['DEYE_EMAIL'] ?? '';
        $this->password = $_ENV['DEYE_PASSWORD'] ?? '';

        $this->client = new Client([
            'timeout' => 15.0,
            'connect_timeout' => 5.0,
            'verify' => false
        ]);

        $this->accessToken = null;
        $this->tokenExpiry = 0;
    }

    private function obtainToken()
    {
        try {
            $hashedPassword = hash('sha256', $this->password);
            $url = $this->baseUrl . "/account/token?appId=" . $this->appId;

            $response = $this->client->post($url, [
                'json' => [
                    'appSecret' => $this->appSecret,
                    'email' => $this->email,
                    'password' => $hashedPassword,
                    'companyId' => '0'  // this when
                ],
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['accessToken'])) {
                $this->accessToken = $data['accessToken'];
                $this->tokenExpiry = time() + ($data['expiresIn'] ?? 3600) - 300;
                return true;
            } else {
                error_log("Deye token error: " . json_encode($data));
                return false;
            }
        } catch (Exception $e) {
            error_log("Deye token exception: " . $e->getMessage());
            return false;
        }
    }

    private function ensureToken()
    {
        if (!$this->accessToken || time() > $this->tokenExpiry) {
            return $this->obtainToken();
        }
        return true;
    }



    // this is the deye helper function 
    public function makeRequest($endpoint, $payload = [])
    {
        if (!$this->ensureToken()) {
            throw new Exception("Failed to obtain Deye token");
        }

        $url = $this->baseUrl . $endpoint;

        try {
            $response = $this->client->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->accessToken
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            return $data;

        } catch (RequestException $e) {
            error_log("Deye API request failed: " . $e->getMessage());
            throw new Exception("Deye API communication failed");
        }
    }

    public function getStations($page = 1, $size = 100)
    {
        return $this->makeRequest('/station/list', [
            'page' => $page,
            'size' => $size
        ]);
    }

    public function getStationSummary($stationId)
    {
        $now = new DateTime();
        
        // Fetch history data
        $fetchHistory = function ($granularity, $startAt, $endAt) use ($stationId) {
            try {
                $result = $this->makeRequest('/station/history', [
                    'stationId' => $stationId,
                    'granularity' => $granularity,
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                ]);
                return $result['stationDataItems'] ?? [];
            } catch (Exception $e) {
                error_log("History fetch failed: " . $e->getMessage());
                return [];
            }
        };

        // Today's data
        $todayItems = $fetchHistory(2, 
            (clone $now)->setTime(0, 0, 0)->format('Y-m-d'),
            (clone $now)->modify('+1 day')->format('Y-m-d')
        );

        // Yesterday's data
        $yesterdayItems = $fetchHistory(2,
            (clone $now)->modify('-1 day')->setTime(0, 0, 0)->format('Y-m-d'),
            (clone $now)->setTime(0, 0, 0)->format('Y-m-d')
        );

        // This month's data
        $thisMonthItems = $fetchHistory(3,
            (clone $now)->modify('first day of this month')->format('Y-m'),
            (clone $now)->modify('first day of next month')->format('Y-m')
        );

        // Parse energy data
        $parseEnergyData = function ($items) {
            return array_reduce($items, function ($acc, $item) {
                return [
                    'solarProduction' => $acc['solarProduction'] + (floatval($item['generationValue'] ?? 0)),
                    'exportToGrid' => $acc['exportToGrid'] + (floatval($item['gridValue'] ?? 0)),
                    'importFromGrid' => $acc['importFromGrid'] + (floatval($item['purchaseValue'] ?? 0)),
                    'consumption' => $acc['consumption'] + (floatval($item['consumptionValue'] ?? 0)),
                    'chargeEnergy' => $acc['chargeEnergy'] + (floatval($item['chargeValue'] ?? 0)),
                    'dischargeEnergy' => $acc['dischargeEnergy'] + (floatval($item['dischargeValue'] ?? 0)),
                ];
            }, [
                'solarProduction' => 0, 'exportToGrid' => 0, 'importFromGrid' => 0,
                'consumption' => 0, 'chargeEnergy' => 0, 'dischargeEnergy' => 0
            ]);
        };

        return [
            'today' => $parseEnergyData($todayItems),
            'yesterday' => $parseEnergyData($yesterdayItems),
            'thisMonth' => $parseEnergyData($thisMonthItems),
            'debug' => [
                'todayRecords' => count($todayItems),
                'yesterdayRecords' => count($yesterdayItems),
                'thisMonthRecords' => count($thisMonthItems)
            ]
        ];
    }

    public function getStationDevices($stationId, $page = 1, $size = 50)
    {
        return $this->makeRequest('/station/device', [
            'page' => $page,
            'size' => $size,
            'stationIds' => [(int)$stationId]
        ]);
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $formdata = json_decode(file_get_contents("php://input", true), true);
    
    // 🛡️ HANDHSHAKE VALIDATION - Check if request has required parameters
    if (!isset($formdata["uid"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "error" => "Handshake failed: uid required"
        ]);
        exit();
    }

    // ✅ HANDHSHAKE SUCCESSFUL - Process requests
    
    // 🟢 VICTRON API 
    if (isset($formdata["start_time"], $formdata["end_time"], $formdata["instid"])) {
        $inst_id = $formdata["instid"];
        $startTime = strtotime($formdata["start_time"]);
        $endTime = strtotime($formdata["end_time"]);
       
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://vrmapi.victronenergy.com/v2/installations/" . $inst_id . "/stats?type=kwh&start=" . $startTime . "&end=" . $endTime,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "X-Authorization: Token fd6e4a7c9def83919bd187284cd7e882d9902f3f6405ca45a54d457fcaf1a3ed",
                "cache-control: no-cache"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        
        if ($err) {
            echo json_encode(["success" => false, "error" => $err]);
            exit();
        } else {
            $res = json_decode($response, true);
            echo json_encode($res);
            exit();
        }
    }
    
    // 🟢 DEYE API - Get Stations List
    elseif (isset($formdata["deye_action"]) && $formdata["deye_action"] == "get_stations") {
        try {
            $deyeService = new DeyeCloudService();
            $data = $deyeService->getStations(
                $formdata['page'] ?? 1,
                $formdata['size'] ?? 100
            );

            echo json_encode([
                'success' => true,
                'data' => $data['stationList'] ?? $data['data'] ?? [],
                'total' => count($data['stationList'] ?? $data['data'] ?? [])
            ]);
            exit();
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
            exit();
        }
    }
    
    // 🟢 DEYE API - Get Station Summary
    elseif (isset($formdata["deye_action"]) && $formdata["deye_action"] == "get_summary") {
        try {
            $stationId = $formdata["stationId"] ?? null;
            if (!$stationId) {
                throw new Exception("stationId is required");
            }

            $deyeService = new DeyeCloudService();
            $summary = $deyeService->getStationSummary($stationId);

            echo json_encode([
                'success' => true,
                'stationId' => $stationId,
                'summary' => $summary
            ]);
            exit();
            
        } catch (Exception $err) {
            echo json_encode(["success" => false, "error" => $err->getMessage()]);
            exit();
        }
    }
    
    // 🟢 DEYE API - Get Station Devices
    elseif (isset($formdata["deye_action"]) && $formdata["deye_action"] == "get_devices") {
        try {
            $stationId = $formdata["stationId"] ?? null;
            if (!$stationId) {
                throw new Exception("stationId is required");
            }

            $deyeService = new DeyeCloudService();
            $response = $deyeService->getStationDevices(
                $stationId,
                $formdata['page'] ?? 1,
                $formdata['size'] ?? 50
            );
            
            echo json_encode([
                'success' => true,
                'stationId' => (int)$stationId,
                'data' => $response,
                'deviceCount' => count($response['deviceListItems'] ?? [])
            ]);
            exit();
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
            exit();
        }
    }
    
    // Invalid request
    else {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "error" => "Invalid request parameters"
        ]);
        exit();
    }
}
?>