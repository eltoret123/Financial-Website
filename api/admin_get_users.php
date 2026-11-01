<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\User;

require_once __DIR__ . "/../vendor/autoload.php";

function handleAdminGetUsersRequest(EntityManagerInterface $entityManager, array $query): array
{
    $adminUserId = $query["admin_user_id"] ?? null;

    if (!$adminUserId) {
        return [400, [
            "success" => false,
            "message" => "Missing admin_user_id"
        ]];
    }

    $adminUser = $entityManager->find(User::class, (int) $adminUserId);

    if (!$adminUser || !method_exists($adminUser, "isAdmin") || !$adminUser->isAdmin()) {
        return [403, [
            "success" => false,
            "message" => "Only admins can view users"
        ]];
    }

    $users = $entityManager->getRepository(User::class)->findBy([], ["userId" => "ASC"]);

    $userData = [];

    foreach ($users as $user) {
        $suspendedUntil = method_exists($user, "getSuspendedUntil")
            ? $user->getSuspendedUntil()
            : null;

        $userData[] = [
            "user_id" => $user->getUserId(),
            "username" => $user->getUsername(),
            "email" => $user->getEmail(),
            "admin" => method_exists($user, "isAdmin") ? $user->isAdmin() : false,
            "suspended_until" => $suspendedUntil
                ? $suspendedUntil->format("Y-m-d H:i:s")
                : null
        ];
    }

    return [200, [
        "success" => true,
        "users" => $userData
    ]];
}

function runAdminGetUsersEndpoint(): void
{
    header("Access-Control-Allow-Origin: *");
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

    try {
        $entityManager = require __DIR__ . "/../bootstrap.php";
        [$statusCode, $response] = handleAdminGetUsersRequest($entityManager, $_GET);
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
    runAdminGetUsersEndpoint();
}
