<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class DeyeCloudAPI
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

        $this->baseUrl = $_ENV['DEYE_BASE_URL'];
        $this->appId = $_ENV['DEYE_APP_ID'];
        $this->appSecret = $_ENV['DEYE_APP_SECRET'];
        $this->email = $_ENV['DEYE_EMAIL'];
        $this->password = $_ENV['DEYE_PASSWORD'];

        $this->client = new Client([
            'timeout' => 10.0,
            'verify' => false
        ]);

        $this->accessToken = null;
        $this->tokenExpiry = 0;
    }

    /**
     * 1️⃣ Obtain Access Token
     */
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
                    'companyId' => '0'
                ],
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['accessToken'])) {
                $this->accessToken = $data['accessToken'];
                $this->tokenExpiry = time() + ($data['expiresIn'] ?? 3600);
                error_log("✅ Token obtained successfully");
                return true;
            } else {
                error_log("❌ Failed to get token: " . json_encode($data));
                return false;
            }
        } catch (Exception $e) {
            error_log("❌ Token fetch error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 2️⃣ Ensure valid token
     */
    private function ensureToken()
    {
        if (!$this->accessToken || time() > $this->tokenExpiry) {
            return $this->obtainToken();
        }
        return true;
    }

    /**
     * 3️⃣ Helper POST request
     */
    public function deyePost($endpoint, $payload = [])
    {
        if (!$this->ensureToken()) {
            throw new Exception("Failed to obtain valid token");
        }

        $url = $this->baseUrl . $endpoint;

        error_log("🌐 Making request to: " . $url);
        error_log("📤 Payload: " . json_encode($payload));

        try {
            $response = $this->client->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->accessToken
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            error_log("✅ API Response status: " . $response->getStatusCode());

            return $data;
        } catch (RequestException $e) {
            error_log("❌ Deye API Error [" . $endpoint . "]");

            if ($e->hasResponse()) {
                $response = $e->getResponse();
                error_log("🔍 Status: " . $response->getStatusCode());
                error_log("🔍 Response Data: " . $response->getBody());
            }

            throw $e;
        }
    }

    /**
     * Get connect status text
     */
    public function getConnectStatusText($status)
    {
        $statusMap = [
            0 => "Offline",
            1 => "Online",
            2 => "Alert"
        ];
        return $statusMap[$status] ?? "Unknown";
    }

    /**
     * Get product type
     */
    public function getProductType($productId)
    {
        $productMap = [
            "0_5412_1" => "3Phase High-Voltage Hybrid",
            "0_5411_1" => "3Phase Low-Voltage Hybrid",
            "0_5407_1" => "1Phase Hybrid"
        ];
        return $productMap[$productId] ?? $productId ?? "Unknown";
    }
}

// Initialize API
$deyeAPI = new DeyeCloudAPI();

/**
 * API Routes Handler
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function sendError($message, $statusCode = 500) {
    sendResponse([
        'success' => false,
        'error' => $message
    ], $statusCode);
}

// Get request path and input data
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    // 🟢 Health Check
    if ($path === '/' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        sendResponse(['message' => '✅ Deye Cloud Backend Server Running']);
    }

    // 🟢 Get all stations
    if ($path === '/api/stations' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $payload = [
                'page' => $input['page'] ?? 1,
                'size' => $input['size'] ?? 100
            ];

            error_log("📡 Fetching all stations with payload: " . json_encode($payload));

            $data = $deyeAPI->deyePost('/station/list', $payload);
            $stationData = $data['stationList'] ?? $data['data'] ?? [];

            sendResponse([
                'success' => true,
                'data' => $stationData,
                'total' => count($stationData)
            ]);
        } catch (Exception $e) {
            error_log("❌ Stations list error: " . $e->getMessage());
            sendError("Failed to fetch stations: " . $e->getMessage(), 500);
        }
    }

    // 🟢 Get station latest data
    if ($path === '/api/stations/latest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stationId = $input['stationId'] ?? null;
        
        if (!$stationId) {
            sendError("stationId is required in request body", 400);
        }

        $payload = ['stationId' => (int)$stationId];
        error_log("📡 Fetching latest data for station: " . $stationId);

        $response = $deyeAPI->deyePost('/station/latest', $payload);

        sendResponse([
            'success' => true,
            'data' => $response
        ]);
    }

    // 🟢 Get station energy summary
    if ($path === '/api/stations/summary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $stationId = $input['stationId'] ?? null;
            if (!$stationId) throw new Exception("stationId is required");

            error_log("📡 Fetching summary for station: " . $stationId);

            $now = new DateTime();

            $fetchHistory = function ($granularity, $startAt, $endAt, $label) use ($deyeAPI, $stationId) {
                $payload = [
                    'stationId' => $stationId,
                    'granularity' => $granularity,
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                ];

                try {
                    $result = $deyeAPI->deyePost('/station/history', $payload);
                    return $result['stationDataItems'] ?? [];
                } catch (Exception $error) {
                    error_log("❌ {$label} fetch error: " . $error->getMessage());
                    return [];
                }
            };

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

            // Fetch data for all periods
            $todayItems = $fetchHistory(2, 
                (clone $now)->setTime(0, 0, 0)->format('Y-m-d'),
                (clone $now)->modify('+1 day')->format('Y-m-d'),
                "today"
            );

            $yesterdayItems = $fetchHistory(2,
                (clone $now)->modify('-1 day')->setTime(0, 0, 0)->format('Y-m-d'),
                (clone $now)->setTime(0, 0, 0)->format('Y-m-d'),
                "yesterday"
            );

            $thisMonthItems = $fetchHistory(3,
                (clone $now)->modify('first day of this month')->format('Y-m'),
                (clone $now)->modify('first day of next month')->format('Y-m'),
                "this month"
            );

            $lastMonthItems = $fetchHistory(3,
                (clone $now)->modify('first day of last month')->format('Y-m'),
                (clone $now)->modify('first day of this month')->format('Y-m'),
                "last month"
            );

            $summary = [
                'today' => $parseEnergyData($todayItems),
                'yesterday' => $parseEnergyData($yesterdayItems),
                'thisMonth' => $parseEnergyData($thisMonthItems),
                'lastMonth' => $parseEnergyData($lastMonthItems),
            ];

            sendResponse([
                'success' => true,
                'stationId' => $stationId,
                'summary' => $summary,
                'debug' => [
                    'todayRecords' => count($todayItems),
                    'yesterdayRecords' => count($yesterdayItems),
                    'thisMonthRecords' => count($thisMonthItems),
                    'lastMonthRecords' => count($lastMonthItems),
                ]
            ]);

        } catch (Exception $err) {
            error_log("❌ Station Summary Error: " . $err->getMessage());
            sendError($err->getMessage(), 500);
        }
    }

    // 🟢 Get devices for stations
    if ($path === '/api/stations/devices' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $page = $input['page'] ?? 1;
        $size = $input['size'] ?? 20;
        $stationIds = $input['stationIds'] ?? [];

        if (!$stationIds || !is_array($stationIds) || empty($stationIds)) {
            sendError("stationIds array is required and cannot be empty", 400);
        }

        if (count($stationIds) > 10) {
            sendError("Maximum 10 stations per batch allowed", 400);
        }

        $payload = [
            'page' => (int)$page,
            'size' => (int)$size,
            'stationIds' => array_map('intval', $stationIds)
        ];

        error_log("📡 Fetching devices for stations: " . json_encode($stationIds));

        $response = $deyeAPI->deyePost('/station/device', $payload);

        sendResponse([
            'success' => true,
            'data' => $response,
            'pagination' => [
                'page' => $payload['page'],
                'size' => $payload['size'],
                'total' => $response['total'] ?? 0
            ]
        ]);
    }

    // 🟢 Get devices for single station
    if ($path === '/api/stations/devices/single' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $stationId = $input['stationId'] ?? null;
        $page = $input['page'] ?? 1;
        $size = $input['size'] ?? 50;

        if (!$stationId) {
            sendError("stationId is required", 400);
        }

        $payload = [
            'page' => (int)$page,
            'size' => (int)$size,
            'stationIds' => [(int)$stationId]
        ];

        error_log("📡 Fetching devices for station: " . $stationId);

        $response = $deyeAPI->deyePost('/station/device', $payload);

        // Enhanced response with device status mapping
        $deviceListItems = $response['deviceListItems'] ?? [];
        $enhancedItems = array_map(function ($device) use ($deyeAPI) {
            return array_merge($device, [
                'connectStatusText' => $deyeAPI->getConnectStatusText($device['connectStatus'] ?? 0),
                'isOnline' => ($device['connectStatus'] ?? 0) === 1,
                'isOffline' => ($device['connectStatus'] ?? 0) === 0,
                'isAlert' => ($device['connectStatus'] ?? 0) === 2
            ]);
        }, $deviceListItems);

        $enhancedResponse = array_merge($response, ['deviceListItems' => $enhancedItems]);

        sendResponse([
            'success' => true,
            'stationId' => (int)$stationId,
            'data' => $enhancedResponse,
            'deviceCount' => count($enhancedItems),
            'pagination' => [
                'page' => $payload['page'],
                'size' => $payload['size'],
                'total' => $response['total'] ?? 0
            ]
        ]);
    }

    // 🟢 Get device by serial number
    if ($path === '/api/devices/by-sn' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $deviceSn = $input['deviceSn'] ?? null;
        $stationId = $input['stationId'] ?? null;

        if (!$deviceSn) {
            sendError("deviceSn is required", 400);
        }

        if (!$stationId) {
            sendError("stationId is required", 400);
        }

        $payload = [
            'page' => 1,
            'size' => 100,
            'stationIds' => [(int)$stationId]
        ];

        error_log("📡 Fetching device by SN: " . $deviceSn . " from station: " . $stationId);

        $response = $deyeAPI->deyePost('/station/device', $payload);

        // Find specific device by serial number
        $device = null;
        foreach ($response['deviceListItems'] ?? [] as $item) {
            if ($item['deviceSn'] === $deviceSn) {
                $device = $item;
                break;
            }
        }

        if (!$device) {
            sendError("Device with SN {$deviceSn} not found in station {$stationId}", 404);
        }

        // Enhanced device info
        $enhancedDevice = array_merge($device, [
            'connectStatusText' => $deyeAPI->getConnectStatusText($device['connectStatus'] ?? 0),
            'isOnline' => ($device['connectStatus'] ?? 0) === 1,
            'isOffline' => ($device['connectStatus'] ?? 0) === 0,
            'isAlert' => ($device['connectStatus'] ?? 0) === 2,
            'productType' => $deyeAPI->getProductType($device['productId'] ?? '')
        ]);

        sendResponse([
            'success' => true,
            'data' => $enhancedDevice
        ]);
    }

    // Route not found
    sendError("Endpoint not found", 404);

} catch (Exception $e) {
    error_log("❌ API Error: " . $e->getMessage());
    sendError($e->getMessage(), 500);
}
?>