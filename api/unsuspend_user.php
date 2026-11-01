<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\User;

require_once __DIR__ . "/../vendor/autoload.php";

function handleUnsuspendUserRequest(EntityManagerInterface $entityManager, ?array $input): array
{
    if (!$input) {
        return [400, [
            "success" => false,
            "message" => "Invalid JSON input"
        ]];
    }

    $adminUserId = $input["admin_user_id"] ?? null;
    $targetUserId = $input["target_user_id"] ?? null;

    if (!$adminUserId || !$targetUserId) {
        return [400, [
            "success" => false,
            "message" => "Missing admin_user_id or target_user_id"
        ]];
    }

    $adminUser = $entityManager->find(User::class, (int) $adminUserId);
    $targetUser = $entityManager->find(User::class, (int) $targetUserId);

    if (!$adminUser || !method_exists($adminUser, "isAdmin") || !$adminUser->isAdmin()) {
        return [403, [
            "success" => false,
            "message" => "Only admins can unsuspend users"
        ]];
    }

    if (!$targetUser) {
        return [404, [
            "success" => false,
            "message" => "Target user not found"
        ]];
    }

    $targetUser->setSuspendedUntil(null);

    $entityManager->persist($targetUser);
    $entityManager->flush();

    return [200, [
        "success" => true,
        "message" => "User unsuspended successfully"
    ]];
}

function runUnsuspendUserEndpoint(): void
{
    header("Access-Control-Allow-Origin: *");
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

    try {
        $entityManager = require __DIR__ . "/../bootstrap.php";
        $input = json_decode(file_get_contents("php://input"), true);
        [$statusCode, $response] = handleUnsuspendUserRequest($entityManager, $input);
        http_response_code($statusCode);
        echo json_encode($response);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Server error",
            "error" => $e->getMessage()
        ]);
    }
}

if (!defined("PHPUNIT_RUNNING")) {
    runUnsuspendUserEndpoint();
}
