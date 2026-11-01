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
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed"
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

function getHoldingStock(OwnershipShare $holding): Stock
{
    if (method_exists($holding, "getStock")) {
        return $holding->getStock();
    }

    return getPropertyValue($holding, "stock");
}

function getHoldingTicker(OwnershipShare $holding): string
{
    return getStockTicker(getHoldingStock($holding));
}

function getHoldingShares(OwnershipShare $holding): float
{
    if (method_exists($holding, "getSharesHeld")) {
        return $holding->getSharesHeld();
    }

    return (float)getPropertyValue($holding, "sharesHeld");
}

function setHoldingShares(OwnershipShare $holding, float $shares): void
{
    if (method_exists($holding, "setSharesHeld")) {
        $holding->setSharesHeld($shares);
        return;
    }

    setPropertyValue($holding, "sharesHeld", number_format($shares, 6, ".", ""));
    setPropertyValue($holding, "updatedAt", new DateTimeImmutable());
}

function getHoldingAvgBuyPrice(OwnershipShare $holding): float
{
    if (method_exists($holding, "getAvgBuyPrice")) {
        return $holding->getAvgBuyPrice();
    }

    return (float)getPropertyValue($holding, "avgBuyPrice");
}

function setHoldingAvgBuyPrice(OwnershipShare $holding, float $avgBuyPrice): void
{
    if (method_exists($holding, "setAvgBuyPrice")) {
        $holding->setAvgBuyPrice($avgBuyPrice);
        return;
    }

    setPropertyValue($holding, "avgBuyPrice", number_format($avgBuyPrice, 2, ".", ""));
    setPropertyValue($holding, "updatedAt", new \DateTime());
}

function createOwnershipShare(
    Portfolio $portfolio,
    Stock $stock,
    float $sharesHeld,
    float $avgBuyPrice
): OwnershipShare {
    $reflection = new ReflectionClass(OwnershipShare::class);
    $holding = $reflection->newInstanceWithoutConstructor();

    setPropertyValue($holding, "portfolio", $portfolio);
    setPropertyValue($holding, "stock", $stock);
    setPropertyValue($holding, "sharesHeld", number_format($sharesHeld, 6, ".", ""));
    setPropertyValue($holding, "avgBuyPrice", number_format($avgBuyPrice, 2, ".", ""));
    setPropertyValue($holding, "updatedAt", new \DateTimeImmutable());

    return $holding;
}

function createPortfolioTransaction(
    Portfolio $portfolio,
    string $ticker,
    string $tradeType,
    float $shares,
    float $price,
    float $total
): PortfolioTransaction {
    $reflection = new ReflectionClass(PortfolioTransaction::class);
    $transaction = $reflection->newInstanceWithoutConstructor();

    setPropertyValue($transaction, "portfolio", $portfolio);
    setPropertyValue($transaction, "ticker", $ticker);
    setPropertyValue($transaction, "tradeType", $tradeType);
    setPropertyValue($transaction, "shares", number_format($shares, 6, ".", ""));
    setPropertyValue($transaction, "price", number_format($price, 2, ".", ""));
    setPropertyValue($transaction, "total", number_format($total, 2, ".", ""));
    setPropertyValue($transaction, "createdAt", new \DateTime());

    return $transaction;
}

function createPortfolioHistory(
    Portfolio $portfolio,
    float $cashBalance,
    float $totalValue
): PortfolioHistory {
    $reflection = new ReflectionClass(PortfolioHistory::class);
    $history = $reflection->newInstanceWithoutConstructor();

    setPropertyValue($history, "portfolio", $portfolio);
    setPropertyValue($history, "cashBalance", number_format($cashBalance, 2, ".", ""));
    setPropertyValue($history, "totalValue", number_format($totalValue, 2, ".", ""));
    setPropertyValue($history, "recordedAt", new \DateTime());

    return $history;
}

function calculateTotalPortfolioValue(
    EntityManager $entityManager,
    Portfolio $portfolio,
    string $latestTradeTicker,
    float $latestTradePrice,
    float $cashBalance
): float {
    $holdings = $entityManager
        ->getRepository(OwnershipShare::class)
        ->findBy([
            "portfolio" => $portfolio
        ]);

    $holdingsValue = 0.0;

    foreach ($holdings as $holding) {
        $shares = getHoldingShares($holding);
        $ticker = getHoldingTicker($holding);

        $valuationPrice = getHoldingAvgBuyPrice($holding);

        if ($ticker === $latestTradeTicker) {
            $valuationPrice = $latestTradePrice;
        }

        $holdingsValue += $shares * $valuationPrice;
    }

    return round($cashBalance + $holdingsValue, 2);
}

$input = json_decode(file_get_contents("php://input"), true);

$userId = isset($input["user_id"]) ? (int)$input["user_id"] : 0;
$ticker = strtoupper(trim($input["symbol"] ?? ""));
$type = strtolower(trim($input["type"] ?? ""));
$quantity = isset($input["quantity"]) ? (float)$input["quantity"] : 0;
$price = isset($input["price"]) ? (float)$input["price"] : 0;

if (
    $userId <= 0 ||
    !$ticker ||
    !in_array($type, ["buy", "sell"], true) ||
    $quantity <= 0 ||
    $price <= 0
) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid trade data"
    ]);
    exit;
}

$total = round($quantity * $price, 2);

try {
    $entityManager = createEntityManager();

    $result = $entityManager->wrapInTransaction(function (EntityManager $entityManager) use (
        $userId,
        $ticker,
        $type,
        $quantity,
        $price,
        $total
    ) {
        $user = $entityManager->find(User::class, $userId);

        if (!$user) {
            throw new Exception("User not found");
        }

        $stock = $entityManager->find(Stock::class, $ticker);

        if (!$stock) {
            throw new Exception("Stock does not exist in database");
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

            $initialHistory = createPortfolioHistory(
                $portfolio,
                10000.00,
                10000.00
            );

            $entityManager->persist($initialHistory);
            $entityManager->flush();
        }

        $portfolioId = getEntityId($portfolio, ["getPortfolioId", "getId"], "portfolioId");
        $cashBalance = getPortfolioCashBalance($portfolio);

        $holding = $entityManager
            ->getRepository(OwnershipShare::class)
            ->findOneBy([
                "portfolio" => $portfolio,
                "stock" => $stock
            ]);

        if ($type === "buy") {
            if ($cashBalance < $total) {
                throw new Exception("Not enough cash to complete this purchase");
            }

            $newCashBalance = round($cashBalance - $total, 2);

            if ($holding) {
                $oldShares = getHoldingShares($holding);
                $oldAvgPrice = getHoldingAvgBuyPrice($holding);

                $newShares = $oldShares + $quantity;
                $newAvgPrice = (($oldShares * $oldAvgPrice) + ($quantity * $price)) / $newShares;

                setHoldingShares($holding, $newShares);
                setHoldingAvgBuyPrice($holding, round($newAvgPrice, 2));
            } else {
                $holding = createOwnershipShare(
                    $portfolio,
                    $stock,
                    $quantity,
                    round($price, 2)
                );

                $entityManager->persist($holding);
            }

            setPortfolioCashBalance($portfolio, $newCashBalance);

            $message = "Stock bought successfully";
        } else {
            if (!$holding) {
                throw new Exception("You do not own this stock");
            }

            $oldShares = getHoldingShares($holding);

            if ($oldShares < $quantity) {
                throw new Exception("Not enough shares to sell");
            }

            $newShares = $oldShares - $quantity;
            $newCashBalance = round($cashBalance + $total, 2);

            if ($newShares <= 0) {
                $entityManager->remove($holding);
            } else {
                setHoldingShares($holding, $newShares);
            }

            setPortfolioCashBalance($portfolio, $newCashBalance);

            $message = "Stock sold successfully";
        }

        $transaction = createPortfolioTransaction(
            $portfolio,
            $ticker,
            $type,
            $quantity,
            round($price, 2),
            $total
        );

        $entityManager->persist($transaction);

        $entityManager->flush();

        $totalPortfolioValue = calculateTotalPortfolioValue(
            $entityManager,
            $portfolio,
            $ticker,
            round($price, 2),
            $newCashBalance
        );

        $historyEntry = createPortfolioHistory(
            $portfolio,
            $newCashBalance,
            $totalPortfolioValue
        );

        $entityManager->persist($historyEntry);
        $entityManager->flush();

        return [
            "success" => true,
            "message" => $message,
            "portfolio_id" => $portfolioId,
            "cash_balance" => $newCashBalance,
            "total_value" => $totalPortfolioValue,
            "trade" => [
                "type" => $type,
                "symbol" => $ticker,
                "quantity" => $quantity,
                "price" => round($price, 2),
                "total" => $total
            ]
        ];
    });

    echo json_encode($result);

} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}