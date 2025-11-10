<!-- this is my file for understanding  -->
 <?php 
 $reqHeader = getallheaders();
 $token = null;

// validate custom api token
if(!isset($reqHeader["Token"])){
  header("content-type : application/json; chatset=uft-8", true , 401);
  echo json_encode([
    "success" => false,
    "message" => "Token not Found"
  ]);
  exit();
}

// Token validator
if( $token != "Token IdeYNazjrEtOVbfPPTh8GXXhkZ"){
  header("content-type : application/json; charset=utf-8 , true , 401");
  echo json_encode([
    "success" => false,
    "message" => "Token is invalid"
  ]);
  exit();
}

// To allow only the post requests 
if($_SERVER["REQUEST_METHOD"]!= "POST"){
  header("content-type : appllication/json; charset=utf-8", true , 405);
  echo json_encode([
    "success" => false ,
    "message" => "THIS METHOD IS NOT ALLOWED"
  ]);
  exit();
}

// Decode the body 
$formData = json_encode(file_get_contents("php://input", true ), true );
  $userID = $formData["userID"] ?? null ;
  $instID = $formData["instID"] ?? null ;
  $token_d = $formData["token_d"] ?? null ;

// condition for checking userID and instID
if(!$userID ||!$instID ) {
  http_response_code(400);
  echo json_encode([
    "success" => false ,
    "message" => "Missing Parameters"
  ]);
  exit();
}

// The helper function to fetch the data
function fectchHistory(
  $stationId,
  $token_d,
  $granularity,
  $startAt,
  $endAt = null 
){
  // this payload is used for the req.
  $payload = [
    "stationid" => (int)$stationId,
    "granularity" => $granularity,
    "startAt" => $startAt 
  ];
  if($endAt){
    $payload["endAt"] = $endAt;
  };

  // curl initi()
  $curl = curl_init() ;
  // curl opration
  curl_setopt_array($curl ,[
    CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/history",
    CURLOPT_RETURNTRANSFER => true ,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10 ,
    CURLOPT_TIMEOUT => 0 ,
    CURLOPT_FOLLOWLOCATION => true ,
    CURLOPT_HTTP_VERSION =>CURL_HTTP_VERSION_1_1 ,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
      "Content-Type : application/json",
      "Authorization : Bearer" .$token_d ,
      "Cookie:cookiesession1=678A3E0EC13FC723FC53C6964C119F37"
    ],
  ]);
  $response = curl_exec($curl);
  curl_close($curl);

  $data = json_decode(
    $response ,
    true
  );

  //  this will give res for all data
  return $data["stationDataItem"] ?? [] ;
}

// helper function to parse that data and the sum up the into one place 
function parseEnergyDate($items){
  $acc = [
    "solarProduction" => 0 ,
    "exportToGrid" => 0 ,
    "importFormGrid" => 0 
  ];
  foreach($items as $item){
    $acc["solarProduction"] += floatval($item["generationValue"] ?? 0);
    $acc["exportToGrid"] += floatval($item["gridvalue"] ?? 0);
    $acc["importFromGrid"] += floatval($item["purchaseValue"] ?? 0);
  };
  
  // then return the final value 
  return $acc ;
}

// Fetch the data for the all time periods Today , Yesterday , This Month , Last Month 

$now = new DateTime();
// this is to for the todays data
$todayItems = fetchHistory(
  $instId,
  $token_d,
  2,
  (clone $now)-> setTime(0 ,0, 0)->format("Y-m-d"),
  (clone $now)-> modify(" +1 day ") -> format("Y-m-d")
);
// this is for the yesterdays data
$yesterdaysItems = fetchHistory(
  $instID,
  $token_d,
  2,
  (clone $now) -> modify("-1 day")-> setTime(0,0,0)->format("Y-m-d"),
  (clone $now) -> setTime(0,0,0) -> format("Y-m-d")
);

$thisMonthItems = fetchHistory(
    $instID,
    $token_d,
    3,
    (clone $now)->modify("first day of this month")->format("Y-m"),
    (clone $now)->modify("first day of next month")->format("Y-m")
);

$lastMonthItems = fectchHistory(
  $instID,
  $token_d,
  3,
  (clone $now) -> modify("first day of last month") -> format("Y-m"),
  (clone $now) -> modify("first day of this month")-> format("Y-m")
);

$summary = [
  "today" => parseEnergyData($todayItems),
  "yesterday" => parseEnergyData($yesterdaysItems),
  "thisMonth" => parseEnergyData($thisMonthItems),
  "lastMonth" => parseEnergyData($lastMonthItems),
];


// Fetch latest data
$curl = curl_init();
curl_setopt_array($curl , [
    CURLOPT_URL => "https://eu1-developer.deyecloud.com/v1.0/station/latest",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode([
      "stationId" => (int)$instID
    ]),
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token_d,
        "Cookie: cookiesession1=678A3E0EC13FC723FC53C6964C119F37"
    ],
]);

$latestResponse = curl_exec($curl);
curl_close($curl);

$latestData = json_decode(
  $latestResponse ,
  true
);

// result 
$result = [
  "sucess" => true ,
  "stationId" => $instID,
  "latest" => $latestData,
  "summary" => $summary 
];

header("content-type: application/json;charset=utf-8");
echo json_encode($result, JSON_PRETTY_PRINT);
exit();

 ?>