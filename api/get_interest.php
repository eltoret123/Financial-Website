<?php

require_once __DIR__ . '/../vendor/autoload.php';

$entityManager = require_once __DIR__ . '/../bootstrap.php';

use Team407\InvestingWebsite\Api\GetInterestHandler;

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

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
    $rawInput = file_get_contents("php://input");
    $input = json_decode((string)$rawInput, true);

    $handler = new GetInterestHandler();
    [$statusCode, $responseBody] = $handler->handle($entityManager, $input);

    http_response_code($statusCode);
    echo json_encode($responseBody);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An unexpected error occurred",
        "error" => $e->getMessage()
    ]);
}