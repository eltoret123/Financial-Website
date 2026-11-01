<?php

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Team407\InvestingWebsite\Entity\OwnershipShare;
use Team407\InvestingWebsite\Entity\Portfolio;
use Team407\InvestingWebsite\Entity\PortfolioHistory;
use Team407\InvestingWebsite\Entity\PortfolioTransaction;
use Team407\InvestingWebsite\Entity\Stock;
use Team407\InvestingWebsite\Entity\User;

require_once __DIR__ . "/../vendor/autoload.php";

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

function createEntityManager(): EntityManager
{
    $envPath = __DIR__ . "/../.env";

    if (!file_exists($envPath)) {
        throw new Exception(".env file not found");
    }

    $env = parse_ini_file($envPath);

    $entityPaths = array_filter([
        __DIR__ . "/../src/Entity",
        __DIR__ . "/../src/Team407/InvestingWebsite/Entity"
    ], "is_dir");

    if (count($entityPaths) === 0) {
        throw new Exception("Entity folder not found");
    }

    $config = ORMSetup::createAttributeMetadataConfiguration(
        paths: $entityPaths,
        isDevMode: true
    );

    $connection = DriverManager::getConnection([
        "driver" => "pdo_mysql",
        "host" => $env["DB_HOST"] ?? "localhost",
        "dbname" => $env["DB_NAME"] ?? "",
        "user" => $env["DB_USER"] ?? "",
        "password" => $env["DB_PASSWORD"] ?? ($env["DB_PASS"] ?? ""),
        "charset" => "utf8mb4"
    ], $config);

    return new EntityManager($connection, $config);
}

function envValue(string $key, ?string $default = null): ?string
{
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

function getPropertyValue(object $entity, string $propertyName)
{
    $reflection = new ReflectionObject($entity);

    while (!$reflection->hasProperty($propertyName)) {
        $reflection = $reflection->getParentClass();

        if (!$reflection) {
            return null;
        }
    }

    $property = $reflection->getProperty($propertyName);
    $property->setAccessible(true);

    return $property->getValue($entity);
}

function setPropertyValue(object $entity, string $propertyName, $value): void
{
    $reflection = new ReflectionObject($entity);

    while (!$reflection->hasProperty($propertyName)) {
        $reflection = $reflection->getParentClass();

        if (!$reflection) {
            throw new Exception("Property {$propertyName} not found");
        }
    }

    $property = $reflection->getProperty($propertyName);
    $property->setAccessible(true);
    $property->setValue($entity, $value);
}

function getEntityId(object $entity, array $possibleMethods, string $fallbackProperty): ?int
{
    foreach ($possibleMethods as $method) {
        if (method_exists($entity, $method)) {
            return (int)$entity->$method();
        }
    }

    $value = getPropertyValue($entity, $fallbackProperty);

    return $value === null ? null : (int)$value;
}

function getPortfolioName(Portfolio $portfolio): string
{
    if (method_exists($portfolio, "getPortfolioName")) {
        return $portfolio->getPortfolioName();
    }

    return (string)getPropertyValue($portfolio, "portfolioName");
}

function getPortfolioCashBalance(Portfolio $portfolio): float
{
    if (method_exists($portfolio, "getCashBalance")) {
        return $portfolio->getCashBalance();
    }

    return (float)getPropertyValue($portfolio, "cashBalance");
}

function setPortfolioCashBalance(Portfolio $portfolio, float $cashBalance): void
{
    if (method_exists($portfolio, "setCashBalance")) {
        $portfolio->setCashBalance($cashBalance);
        return;
    }

    setPropertyValue($portfolio, "cashBalance", number_format($cashBalance, 2, ".", ""));
}

function getStockTicker(Stock $stock): string
{
    if (method_exists($stock, "getTicker")) {
        return $stock->getTicker();
    }

    return (string)getPropertyValue($stock, "ticker");
}

function getStockCompanyName(Stock $stock): ?string
{
    if (method_exists($stock, "getCompanyName")) {
        return $stock->getCompanyName();
    }

    return getPropertyValue($stock, "companyName");
}

function getStockExchange(Stock $stock): ?string
{
    if (method_exists($stock, "getExchange")) {
        return $stock->getExchange();
    }

    return getPropertyValue($stock, "exchange");
}

function getStockCurrency(Stock $stock): ?string
{
    if (method_exists($stock, "getCurrency")) {
        return $stock->getCurrency();
    }

    return getPropertyValue($stock, "currency");
}

function getHoldingStock(OwnershipShare $holding): Stock
{
    if (method_exists($holding, "getStock")) {
        return $holding->getStock();
    }

    return getPropertyValue($holding, "stock");
}

function getHoldingTicker(OwnershipShare $holding): string
{
    $stock = getHoldingStock($holding);
    return getStockTicker($stock);
}

function getHoldingShares(OwnershipShare $holding): float
{
    if (method_exists($holding, "getSharesHeld")) {
        return $holding->getSharesHeld();
    }

    return (float)getPropertyValue($holding, "sharesHeld");
}

function getHoldingAvgBuyPrice(OwnershipShare $holding): float
{
    if (method_exists($holding, "getAvgBuyPrice")) {
        return $holding->getAvgBuyPrice();
    }

    return (float)getPropertyValue($holding, "avgBuyPrice");
}

function getHoldingUpdatedAt(OwnershipShare $holding): ?DateTimeImmutable
{
    if (method_exists($holding, "getUpdatedAt")) {
        return $holding->getUpdatedAt();
    }

    return getPropertyValue($holding, "updatedAt");
}

function createPortfolioHistory(Portfolio $portfolio, float $cashBalance, float $totalValue): PortfolioHistory
{
    $reflection = new ReflectionClass(PortfolioHistory::class);
    $history = $reflection->newInstanceWithoutConstructor();

    setPropertyValue($history, "portfolio", $portfolio);
    setPropertyValue($history, "cashBalance", number_format($cashBalance, 2, ".", ""));
    setPropertyValue($history, "totalValue", number_format($totalValue, 2, ".", ""));
    setPropertyValue($history, "recordedAt", new \DateTime());

    return $history;
}

function getHistoryRecordedAt(PortfolioHistory $history): \DateTimeInterface
{
    if (method_exists($history, "getRecordedAt")) {
        return $history->getRecordedAt();
    }

    return getPropertyValue($history, "recordedAt");
}

function getHistoryCashBalance(PortfolioHistory $history): float
{
    if (method_exists($history, "getCashBalance")) {
        return $history->getCashBalance();
    }

    return (float)getPropertyValue($history, "cashBalance");
}

function getHistoryTotalValue(PortfolioHistory $history): float
{
    if (method_exists($history, "getTotalValue")) {
        return $history->getTotalValue();
    }

    $totalValue = getPropertyValue($history, "totalValue");

    return $totalValue === null
        ? getHistoryCashBalance($history)
        : (float)$totalValue;
}

function getTransactionTicker(PortfolioTransaction $transaction): string
{
    if (method_exists($transaction, "getTicker")) {
        return $transaction->getTicker();
    }

    $ticker = getPropertyValue($transaction, "ticker");

    if ($ticker !== null) {
        return (string)$ticker;
    }

    $stock = getPropertyValue($transaction, "stock");

    return $stock ? getStockTicker($stock) : "";
}

function getTransactionTradeType(PortfolioTransaction $transaction): string
{
    if (method_exists($transaction, "getTradeType")) {
        return $transaction->getTradeType();
    }

    return (string)getPropertyValue($transaction, "tradeType");
}

function getTransactionShares(PortfolioTransaction $transaction): float
{
    if (method_exists($transaction, "getShares")) {
        return $transaction->getShares();
    }

    return (float)getPropertyValue($transaction, "shares");
}

function getTransactionPrice(PortfolioTransaction $transaction): float
{
    if (method_exists($transaction, "getPrice")) {
        return $transaction->getPrice();
    }

    return (float)getPropertyValue($transaction, "price");
}

function getTransactionTotal(PortfolioTransaction $transaction): float
{
    if (method_exists($transaction, "getTotal")) {
        return $transaction->getTotal();
    }

    return (float)getPropertyValue($transaction, "total");
}

function getTransactionCreatedAt(PortfolioTransaction $transaction): \DateTimeInterface
{
    if (method_exists($transaction, "getCreatedAt")) {
        return $transaction->getCreatedAt();
    }

    $date = getPropertyValue($transaction, "createdAt") ?? getPropertyValue($transaction, "created_at");
    return $date ?? new \DateTime();
}

function toAlpacaSymbol(string $symbol): string
{
    return str_replace("-", ".", strtoupper(trim($symbol)));
}

function isValidAlpacaUsSymbol(string $symbol): bool
{
    return preg_match("/^[A-Z]{1,5}(\.[A-Z])?$/", $symbol) === 1;
}

function httpsGetWithSocket(string $url, array $headers = []): array
{
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

function fetchLatestPricesForHoldings(array $holdings): array
{
    $alpacaKey = envValue("ALPACA_KEY_ID");
    $alpacaSecret = envValue("ALPACA_SECRET_KEY");
    $alpacaDataUrl = envValue("ALPACA_DATA_URL", "https://data.alpaca.markets/v2");

    if (!$alpacaKey || !$alpacaSecret || count($holdings) === 0) {
        return [];
    }

    $symbols = [];

    foreach ($holdings as $holding) {
        $symbol = toAlpacaSymbol(getHoldingTicker($holding));

        if (isValidAlpacaUsSymbol($symbol)) {
            $symbols[] = $symbol;
        }
    }

    $symbols = array_values(array_unique($symbols));

    if (count($symbols) === 0) {
        return [];
    }

    $prices = [];
    $symbolChunks = array_chunk($symbols, 75);

    foreach ($symbolChunks as $symbolChunk) {
        $symbolsString = implode(",", $symbolChunk);

        $url = rtrim($alpacaDataUrl, "/") . "/stocks/snapshots?symbols=" . urlencode($symbolsString) . "&feed=iex";

        $apiResult = httpsGetWithSocket($url, [
            "APCA-API-KEY-ID" => $alpacaKey,
            "APCA-API-SECRET-KEY" => $alpacaSecret
        ]);

        if (!$apiResult["success"]) {
            continue;
        }

        $response = $apiResult["body"];
        $statusCode = $apiResult["statusCode"];

        if ($statusCode < 200 || $statusCode >= 300) {
            continue;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            continue;
        }

        $snapshots = $data["snapshots"] ?? $data;

        foreach ($snapshots as $symbol => $snapshot) {
            $latestTrade = $snapshot["latestTrade"] ?? [];
            $dailyBar = $snapshot["dailyBar"] ?? [];

            $price = $latestTrade["p"] ?? $dailyBar["c"] ?? null;

            if ($price !== null && (float)$price > 0) {
                $prices[$symbol] = (float)$price;
            }
        }
    }

    return $prices;
}

function getCurrentPriceForHolding(OwnershipShare $holding, array $currentPriceMap): float
{
    $displaySymbol = strtoupper(trim(getHoldingTicker($holding)));
    $alpacaSymbol = toAlpacaSymbol($displaySymbol);

    if (isset($currentPriceMap[$alpacaSymbol])) {
        return (float)$currentPriceMap[$alpacaSymbol];
    }

    if (isset($currentPriceMap[$displaySymbol])) {
        return (float)$currentPriceMap[$displaySymbol];
    }

    return getHoldingAvgBuyPrice($holding);
}

$userId = isset($_GET["user_id"]) ? (int)$_GET["user_id"] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid user_id"
    ]);
    exit;
}

try {
    $entityManager = createEntityManager();

    $user = $entityManager->find(User::class, $userId);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    $portfolio = $entityManager
        ->getRepository(Portfolio::class)
        ->findOneBy([
            "user" => $user
        ]);

    if (!$portfolio) {
        $portfolio = new Portfolio($user, "Default Portfolio");
        setPortfolioCashBalance($portfolio, 10000.00);

        $entityManager->persist($portfolio);
        $entityManager->flush();

        $historyEntry = createPortfolioHistory($portfolio, 10000.00, 10000.00);

        $entityManager->persist($historyEntry);
        $entityManager->flush();
    }

    $portfolioId = getEntityId($portfolio, ["getPortfolioId", "getId"], "portfolioId");

    $holdings = $entityManager
        ->getRepository(OwnershipShare::class)
        ->findBy([
            "portfolio" => $portfolio
        ]);

    usort($holdings, function (OwnershipShare $a, OwnershipShare $b) {
        return strcmp(getHoldingTicker($a), getHoldingTicker($b));
    });

    $cashBalance = getPortfolioCashBalance($portfolio);

    $currentPriceMap = fetchLatestPricesForHoldings($holdings);

    $currentHoldingsValue = 0.0;

    foreach ($holdings as $holding) {
        $shares = getHoldingShares($holding);
        $currentPrice = getCurrentPriceForHolding($holding, $currentPriceMap);

        $currentHoldingsValue += $shares * $currentPrice;
    }

    $currentTotalValue = round($cashBalance + $currentHoldingsValue, 2);

    $history = $entityManager
        ->getRepository(PortfolioHistory::class)
        ->findBy(
            ["portfolio" => $portfolio],
            ["recordedAt" => "ASC"]
        );

    if (!$history || count($history) === 0) {
        $historyEntry = createPortfolioHistory(
            $portfolio,
            $cashBalance,
            $currentTotalValue
        );

        $entityManager->persist($historyEntry);
        $entityManager->flush();

        $history = [$historyEntry];
    }

    $latestHistory = $history[count($history) - 1];

    $latestRecordedAt = getHistoryRecordedAt($latestHistory);
    $latestRecordedValue = getHistoryTotalValue($latestHistory);

    $valueChanged = abs($latestRecordedValue - $currentTotalValue) >= 0.01;
    $secondsSinceLastRecord = time() - $latestRecordedAt->getTimestamp();

    if ($valueChanged && $secondsSinceLastRecord >= 30) {
        $historyEntry = createPortfolioHistory(
            $portfolio,
            $cashBalance,
            $currentTotalValue
        );

        $entityManager->persist($historyEntry);
        $entityManager->flush();

        $history[] = $historyEntry;
        $latestHistory = $historyEntry;
    }

    $historyForResponse = $history;

    $latestResponseEntry = $historyForResponse[count($historyForResponse) - 1];
    $latestResponseValue = getHistoryTotalValue($latestResponseEntry);

    if (abs($latestResponseValue - $currentTotalValue) >= 0.01) {
        $liveHistoryEntry = createPortfolioHistory(
            $portfolio,
            $cashBalance,
            $currentTotalValue
        );

        $historyForResponse[] = $liveHistoryEntry;
    }

    $transactions = $entityManager
        ->getRepository(PortfolioTransaction::class)
        ->findBy(
            ["portfolio" => $portfolio],
            ["createdAt" => "DESC"],
            20
        );

    echo json_encode([
        "success" => true,
        "portfolio" => [
            "portfolio_id" => $portfolioId,
            "portfolio_name" => getPortfolioName($portfolio),
            "cash_balance" => $cashBalance,
            "total_value" => $currentTotalValue
        ],
        "holdings" => array_map(function (OwnershipShare $holding) use ($currentPriceMap) {
            $stock = getHoldingStock($holding);
            $ticker = getStockTicker($stock);
            $shares = getHoldingShares($holding);
            $avgBuyPrice = getHoldingAvgBuyPrice($holding);
            $currentPrice = getCurrentPriceForHolding($holding, $currentPriceMap);

            return [
                "ownership_id" => getEntityId($holding, ["getOwnershipId", "getId"], "ownershipId"),
                "symbol" => $ticker,
                "name" => getStockCompanyName($stock) ?? $ticker,
                "shares" => $shares,
                "avg_buy_price" => $avgBuyPrice,
                "current_price" => round($currentPrice, 2),
                "current_value" => round($shares * $currentPrice, 2),
                "gain_loss" => round(($currentPrice - $avgBuyPrice) * $shares, 2),
                "gain_loss_percent" => $avgBuyPrice > 0
                    ? round((($currentPrice - $avgBuyPrice) / $avgBuyPrice) * 100, 2)
                    : 0,
                "exchange" => getStockExchange($stock) ?? "US",
                "currency" => getStockCurrency($stock) ?? "USD",
                "updated_at" => getHoldingUpdatedAt($holding)
                    ? getHoldingUpdatedAt($holding)->format("Y-m-d H:i:s")
                    : null
            ];
        }, $holdings),
        "history" => array_map(function (PortfolioHistory $entry) {
            return [
                "date" => getHistoryRecordedAt($entry)->format("Y-m-d H:i"),
                "cash_balance" => getHistoryCashBalance($entry),
                "total_value" => getHistoryTotalValue($entry)
            ];
        }, $historyForResponse),
        "transactions" => array_map(function (PortfolioTransaction $transaction) {
            return [
                "transaction_id" => getEntityId($transaction, ["getTransactionId", "getId"], "transactionId"),
                "ticker" => getTransactionTicker($transaction),
                "trade_type" => getTransactionTradeType($transaction),
                "shares" => getTransactionShares($transaction),
                "price" => getTransactionPrice($transaction),
                "total" => getTransactionTotal($transaction),
                "created_at" => getTransactionCreatedAt($transaction)->format("Y-m-d H:i:s")
            ];
        }, $transactions)
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error",
        "error" => $e->getMessage()
    ]);
}