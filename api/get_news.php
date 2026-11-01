<?php

require_once __DIR__ . '/../vendor/autoload.php';

$entityManager = require_once __DIR__ . '/../bootstrap.php';

use Team407\InvestingWebsite\Entity\News;
use Team407\InvestingWebsite\Api\NewsResponseFactory;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

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
    $newsRepository = $entityManager->getRepository(News::class);

    $newsArticles = $newsRepository->findBy(
        [],
        ["publishedAt" => "DESC"]
    );

    $newsArray = (new NewsResponseFactory())->create($newsArticles);

    echo json_encode($newsArray);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch news from database",
        "error" => $e->getMessage()
    ]);
}
