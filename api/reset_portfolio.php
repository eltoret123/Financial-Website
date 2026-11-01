<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\User;
use Team407\InvestingWebsite\Entity\Portfolio;
use Team407\InvestingWebsite\Entity\OwnershipShare;
use Team407\InvestingWebsite\Entity\PortfolioTransaction;
use Team407\InvestingWebsite\Entity\PortfolioHistory;

require_once __DIR__ . "/../vendor/autoload.php";

function setPropertyValue(object $entity, string $propertyName, mixed $value): void
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

function getPropertyValue(object $entity, string $propertyName): mixed
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

function createPortfolioHistory(Portfolio $portfolio, float $cashBalance, float $totalValue): PortfolioHistory
{
    $reflection = new ReflectionClass(PortfolioHistory::class);
    $history = $reflection->newInstanceWithoutConstructor();

    setPropertyValue($history, "portfolio", $portfolio);
    setPropertyValue($history, "cashBalance", number_format($cashBalance, 2, ".", ""));
    setPropertyValue($history, "totalValue", number_format($totalValue, 2, ".", ""));
    setPropertyValue($history, "recordedAt", new DateTime());

    return $history;
}

function purgePortfolioData(EntityManagerInterface $entityManager, Portfolio $portfolio): void
{
    $qb = $entityManager->createQueryBuilder();

    $qb->delete(OwnershipShare::class, "o")
        ->where("o.portfolio = :portfolio")
        ->setParameter("portfolio", $portfolio)
        ->getQuery()
        ->execute();

    $qb->delete(PortfolioTransaction::class, "t")
        ->where("t.portfolio = :portfolio")
        ->setParameter("portfolio", $portfolio)
        ->getQuery()
        ->execute();

    $qb->delete(PortfolioHistory::class, "h")
        ->where("h.portfolio = :portfolio")
        ->setParameter("portfolio", $portfolio)
        ->getQuery()
        ->execute();
}

function handleResetPortfolioRequest(EntityManagerInterface $entityManager, ?array $input): array
{
    $userId = isset($input["user_id"]) ? (int)$input["user_id"] : 0;

    if ($userId <= 0) {
        return [400, [
            "success" => false,
            "message" => "Invalid user_id"
        ]];
    }

    try {
        $entityManager->beginTransaction();

        $user = $entityManager->getRepository(User::class)->find($userId);

        if (!$user) {
            throw new Exception("User not found");
        }

        $portfolio = $entityManager->getRepository(Portfolio::class)->findOneBy(["user" => $user]);

        if (!$portfolio) {
            $portfolio = new Portfolio($user, "Default Portfolio");
            $entityManager->persist($portfolio);
            $entityManager->flush();
        } else {
            purgePortfolioData($entityManager, $portfolio);
            $portfolio->setCashBalance(10000.00);
        }

        $history = createPortfolioHistory($portfolio, 10000.00, 10000.00);
        $entityManager->persist($history);

        $entityManager->flush();
        $entityManager->commit();

        return [200, [
            "success" => true,
            "message" => "Portfolio reset successfully",
            "portfolio_id" => $portfolio->getPortfolioId(),
            "cash_balance" => $portfolio->getCashBalance()
        ]];
    } catch (Throwable $e) {
        if (method_exists($entityManager, "getConnection") && $entityManager->getConnection()->isTransactionActive()) {
            $entityManager->rollback();
        }

        return [500, [
            "success" => false,
            "message" => "Reset failed",
            "error" => $e->getMessage()
        ]];
    }
}

function runResetPortfolioEndpoint(): void
{
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

    $entityManager = null;

    try {
        $input = json_decode(file_get_contents("php://input"), true);
        $userId = isset($input["user_id"]) ? (int)$input["user_id"] : 0;

        if ($userId <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid user_id"
            ]);
            exit;
        }

        $entityManager = require_once __DIR__ . "/../bootstrap.php";

        [$status, $response] = handleResetPortfolioRequest($entityManager, $input);

        http_response_code($status);
        echo json_encode($response);
    } catch (Throwable $e) {
        if ($entityManager && method_exists($entityManager, "getConnection") && $entityManager->getConnection()->isTransactionActive()) {
            $entityManager->rollback();
        }

        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Reset failed",
            "error" => $e->getMessage()
        ]);
    }
}

if (!defined("PHPUNIT_RUNNING")) {
    runResetPortfolioEndpoint();
}
