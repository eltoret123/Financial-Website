<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\Subforum;
use Team407\InvestingWebsite\Entity\User;

require_once __DIR__ . "/../vendor/autoload.php";

function handleDeleteForumResponseRequest(EntityManagerInterface $entityManager, ?array $input): array
{
    if (!$input) {
        return [400, [
            "success" => false,
            "message" => "Invalid JSON input"
        ]];
    }

    $userId = $input["user_id"] ?? null;
    $responseId = $input["response_id"] ?? null;

    if (!$userId || !$responseId) {
        return [400, [
            "success" => false,
            "message" => "Missing user_id or response_id"
        ]];
    }

    $user = $entityManager->find(User::class, (int)$userId);
    $responseItem = $entityManager->find(Subforum::class, (int)$responseId);

    if (!$user) {
        return [404, [
            "success" => false,
            "message" => "User not found"
        ]];
    }

    if (!$responseItem) {
        return [404, [
            "success" => false,
            "message" => "Response not found"
        ]];
    }

    $responseOwner = $responseItem->getUser();
    $isOwner = $responseOwner && $responseOwner->getUserId() === $user->getUserId();
    $isAdmin = method_exists($user, "isAdmin") && $user->isAdmin();

    if (!$isOwner && !$isAdmin) {
        return [403, [
            "success" => false,
            "message" => "You are not allowed to delete this response"
        ]];
    }

    $entityManager->remove($responseItem);
    $entityManager->flush();

    return [200, [
        "success" => true,
        "message" => "Response deleted successfully"
    ]];
}

function runDeleteForumResponseEndpoint(): void
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

        [$statusCode, $response] = handleDeleteForumResponseRequest($entityManager, $input);

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
    runDeleteForumResponseEndpoint();
}
