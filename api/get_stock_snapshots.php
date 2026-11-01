<?php

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\Stock;

$allowedOrigins = [
    "http://localhost:5173",
    "http://localhost:5174",
    "http://a25sewt407.studev.groept.be",
    "https://a25sewt407.studev.groept.be"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only GET requests are allowed"
    ]);
    exit;
}

$forceRefresh = isset($_GET["force"]) && $_GET["force"] === "1";

function jsonError(int $statusCode, string $message, array $extra = []): void {
    http_response_code($statusCode);
    echo json_encode(array_merge([
        "success" => false,
        "message" => $message
    ], $extra));
    exit;
}

function getEntityManager(): EntityManagerInterface {
    $bootstrapResult = require __DIR__ . "/../bootstrap.php";

    if (isset($GLOBALS["entityManager"]) && $GLOBALS["entityManager"] instanceof EntityManagerInterface) {
        return $GLOBALS["entityManager"];
    }

    if (isset($entityManager) && $entityManager instanceof EntityManagerInterface) {
        return $entityManager;
    }

    if ($bootstrapResult instanceof EntityManagerInterface) {
        return $bootstrapResult;
    }

    if (is_array($bootstrapResult)) {
        if (isset($bootstrapResult["entityManager"]) && $bootstrapResult["entityManager"] instanceof EntityManagerInterface) {
            return $bootstrapResult["entityManager"];
        }

        if (isset($bootstrapResult["em"]) && $bootstrapResult["em"] instanceof EntityManagerInterface) {
            return $bootstrapResult["em"];
        }
    }

    jsonError(500, "Doctrine EntityManager could not be loaded from bootstrap.php");
}

function envValue(string $key, ?string $default = null): ?string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== "") {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== "") {
        return $_SERVER[$key];
    }

    $envPath = __DIR__ . "/../.env";

    if (file_exists($envPath)) {
        $env = parse_ini_file($envPath);

        if (isset($env[$key]) && $env[$key] !== "") {
            return $env[$key];
        }
    }

    return $default;
}

function entityValue(object $entity, array $methods, array $properties, mixed $default = null): mixed {
    foreach ($methods as $method) {
        if (method_exists($entity, $method)) {
            return $entity->$method();
        }
    }

    $reflection = new ReflectionClass($entity);

    foreach ($properties as $property) {
        if ($reflection->hasProperty($property)) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            return $prop->getValue($entity);
        }
    }

    return $default;
}

function toAlpacaSymbol(string $symbol): string {
    return str_replace("-", ".", strtoupper(trim($symbol)));
}

function isValidAlpacaUsSymbol(string $symbol): bool {
    return preg_match("/^[A-Z]{1,5}(\.[A-Z])?$/", $symbol) === 1;
}

function httpsGetWithSocket(string $url, array $headers = []): array {
    $parts = parse_url($url);

    if (!$parts || !isset($parts["host"])) {
        return [
            "success" => false,
            "statusCode" => 500,
            "body" => null,
            "error" => "Invalid URL"
        ];
    }

    $host = $parts["host"];
    $path = ($parts["path"] ?? "/") . (isset($parts["query"]) ? "?" . $parts["query"] : "");

    $context = stream_context_create([
        "ssl" => [
            "verify_peer" => true,
            "verify_peer_name" => true,
            "peer_name" => $host
        ]
    ]);

    $socket = @stream_socket_client(
        "ssl://" . $host . ":443",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return [
            "success" => false,
            "statusCode" => 500,
            "body" => null,
            "error" => "Socket connection failed: " . $errstr
        ];
    }

    stream_set_timeout($socket, 15);

    $request = "GET " . $path . " HTTP/1.0\r\n";
    $request .= "Host: " . $host . "\r\n";

    foreach ($headers as $key => $value) {
        $request .= $key . ": " . $value . "\r\n";
    }

    $request .= "Accept: application/json\r\n";
    $request .= "Accept-Encoding: identity\r\n";
    $request .= "Connection: close\r\n\r\n";

    fwrite($socket, $request);

    $response = "";

    while (!feof($socket)) {
        $response .= fgets($socket, 4096);
    }

    fclose($socket);

    $responseParts = explode("\r\n\r\n", $response, 2);
    $rawHeaders = $responseParts[0] ?? "";
    $body = $responseParts[1] ?? "";

    $statusCode = 500;

    if (preg_match("/HTTP\/\S+\s+(\d+)/", $rawHeaders, $matches)) {
        $statusCode = (int)$matches[1];
    }

    return [
        "success" => true,
        "statusCode" => $statusCode,
        "body" => $body,
        "error" => null
    ];
}

$alpacaKey = envValue("ALPACA_KEY_ID");
$alpacaSecret = envValue("ALPACA_SECRET_KEY");
$alpacaDataUrl = envValue("ALPACA_DATA_URL", "https://data.alpaca.markets/v2");

if (!$alpacaKey || !$alpacaSecret) {
    jsonError(500, "Alpaca API keys are missing in .env");
}

$cacheFile = __DIR__ . "/stock_snapshots_cache.json";
$cacheLifetimeSeconds = 30;

if (
    !$forceRefresh &&
    file_exists($cacheFile) &&
    (time() - filemtime($cacheFile)) < $cacheLifetimeSeconds
) {
    echo file_get_contents($cacheFile);
    exit;
}

try {
    $entityManager = getEntityManager();

    $stocksFromDb = $entityManager
        ->getRepository(Stock::class)
        ->findAll();

    $dbStocks = [];

    foreach ($stocksFromDb as $stockEntity) {
        $ticker = entityValue($stockEntity, ["getTicker"], ["ticker"], "");
        $companyName = entityValue($stockEntity, ["getCompanyName", "getName"], ["companyName", "name"], $ticker);
        $exchange = entityValue($stockEntity, ["getExchange"], ["exchange"], "US");
        $currency = entityValue($stockEntity, ["getCurrency"], ["currency"], "USD");

        if (!$ticker) {
            continue;
        }

        if (strtoupper(trim((string)$currency)) !== "USD") {
            continue;
        }

        $dbStocks[] = [
            "symbol" => strtoupper(trim((string)$ticker)),
            "name" => $companyName ?: strtoupper(trim((string)$ticker)),
            "exchange" => $exchange ?: "US",
            "currency" => $currency ?: "USD"
        ];
    }

} catch (Exception $e) {
    jsonError(500, "Doctrine stock query failed", [
        "error" => $e->getMessage()
    ]);
}

if (count($dbStocks) === 0) {
    $output = json_encode([
        "success" => true,
        "stocks" => [],
        "from_cache" => false,
        "force_refresh" => $forceRefresh,
        "updated_at" => date("Y-m-d H:i:s")
    ]);

    @file_put_contents($cacheFile, $output);
    echo $output;
    exit;
}

$symbols = [];

foreach ($dbStocks as $stock) {
    $alpacaSymbol = toAlpacaSymbol($stock["symbol"]);

    if (isValidAlpacaUsSymbol($alpacaSymbol)) {
        $symbols[] = $alpacaSymbol;
    }
}

$symbols = array_values(array_unique($symbols));

if (count($symbols) === 0) {
    $output = json_encode([
        "success" => true,
        "stocks" => [],
        "from_cache" => false,
        "force_refresh" => $forceRefresh,
        "updated_at" => date("Y-m-d H:i:s")
    ]);

    @file_put_contents($cacheFile, $output);
    echo $output;
    exit;
}

$allSnapshots = [];
$symbolChunks = array_chunk($symbols, 75);

foreach ($symbolChunks as $symbolChunk) {
    $symbolsString = implode(",", $symbolChunk);

    $url = rtrim($alpacaDataUrl, "/") . "/stocks/snapshots?symbols=" . urlencode($symbolsString) . "&feed=iex";

    $apiResult = httpsGetWithSocket($url, [
        "APCA-API-KEY-ID" => $alpacaKey,
        "APCA-API-SECRET-KEY" => $alpacaSecret
    ]);

    if (!$apiResult["success"]) {
        jsonError(500, "Failed to call Alpaca using socket request", [
            "error" => $apiResult["error"]
        ]);
    }

    $response = $apiResult["body"];
    $statusCode = $apiResult["statusCode"];

    $data = json_decode($response, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        jsonError(500, "Alpaca response was not valid JSON", [
            "json_error" => json_last_error_msg(),
            "raw_response" => $response
        ]);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        jsonError($statusCode, "Alpaca returned an error", [
            "status_code" => $statusCode,
            "alpaca_response" => $data
        ]);
    }

    $batchSnapshots = $data["snapshots"] ?? $data;

    if (is_array($batchSnapshots)) {
        $allSnapshots = array_merge($allSnapshots, $batchSnapshots);
    }
}

$stocks = [];

foreach ($dbStocks as $dbStock) {
    $displaySymbol = strtoupper(trim($dbStock["symbol"]));
    $alpacaSymbol = toAlpacaSymbol($displaySymbol);
    $name = $dbStock["name"] ?? $displaySymbol;

    if (!isValidAlpacaUsSymbol($alpacaSymbol)) {
        continue;
    }

    if (!isset($allSnapshots[$alpacaSymbol])) {
        continue;
    }

    $snapshot = $allSnapshots[$alpacaSymbol];

    $latestTrade = $snapshot["latestTrade"] ?? [];
    $dailyBar = $snapshot["dailyBar"] ?? [];
    $previousDailyBar = $snapshot["prevDailyBar"] ?? [];

    $price = $latestTrade["p"] ?? $dailyBar["c"] ?? 0;
    $previousClose = $previousDailyBar["c"] ?? 0;

    $change = 0;
    $changePercent = 0;

    if ($previousClose > 0 && $price > 0) {
        $change = $price - $previousClose;
        $changePercent = ($change / $previousClose) * 100;
    }

    $stocks[] = [
        "symbol" => $displaySymbol,
        "alpacaSymbol" => $alpacaSymbol,
        "name" => $name,
        "price" => round((float)$price, 2),
        "change" => round((float)$change, 2),
        "changesPercentage" => round((float)$changePercent, 2),
        "dayHigh" => isset($dailyBar["h"]) ? round((float)$dailyBar["h"], 2) : null,
        "dayLow" => isset($dailyBar["l"]) ? round((float)$dailyBar["l"], 2) : null,
        "volume" => $dailyBar["v"] ?? null,
        "exchange" => $dbStock["exchange"] ?? "US",
        "currency" => $dbStock["currency"] ?? "USD"
    ];
}

$output = json_encode([
    "success" => true,
    "stocks" => $stocks,
    "from_cache" => false,
    "force_refresh" => $forceRefresh,
    "cached_for_seconds" => $cacheLifetimeSeconds,
    "updated_at" => date("Y-m-d H:i:s")
]);

@file_put_contents($cacheFile, $output);

echo $output;