<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Api\CreateForumResponseHandler;

require_once __DIR__ . "/../vendor/autoload.php";

function handleCreateForumResponseRequest(EntityManagerInterface $entityManager, ?array $input): array
{
    return (new CreateForumResponseHandler())->handle($entityManager, $input);
}

function runCreateForumResponseEndpoint(): void
{
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Content-Type: application/json");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        return;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode([
            "success" => false,
            "message" => "Only POST requests are allowed"
        ]);
        return;
    }

    try {
        $entityManager = require __DIR__ . "/../bootstrap.php";
        $input = json_decode(file_get_contents("php://input"), true);

        [$statusCode, $response] = handleCreateForumResponseRequest($entityManager, $input);

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
    runCreateForumResponseEndpoint();
}
