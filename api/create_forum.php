<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Api\CreateForumHandler;

require_once __DIR__ . "/../vendor/autoload.php";

function handleCreateForumRequest(EntityManagerInterface $entityManager, ?array $input): array
{
    return (new CreateForumHandler())->handle($entityManager, $input);
}

/**
 * Executes the API endpoint routing logic.
 * Accept the entity manager as a parameter to support full testing isolation.
 */
function runCreateForumEndpoint(EntityManagerInterface $entityManager): void
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
        $input = json_decode(file_get_contents("php://input"), true);

        [$statusCode, $response] = handleCreateForumRequest($entityManager, $input);

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
    $entityManager = require __DIR__ . "/../bootstrap.php";
    runCreateForumEndpoint($entityManager);
}
