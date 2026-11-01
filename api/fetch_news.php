<?php

require_once __DIR__ . '/../vendor/autoload.php';
$entityManager = require_once __DIR__ . '/../bootstrap.php';

use Team407\InvestingWebsite\Entity\News;
use Team407\InvestingWebsite\Entity\Sector;

// --- Configuration ---
$apiToken = $_ENV['SectorNewsapiKey'];

echo "[" . date('Y-m-d H:i:s') . "] Starting global news fetch sequence...\n";

$sectorRepository = $entityManager->getRepository(Sector::class);
$newsRepository = $entityManager->getRepository(News::class);

// 1. Fetch ALL sectors from the database
$sectors = $sectorRepository->findAll();

if (empty($sectors)) {
    die("[" . date('Y-m-d H:i:s') . "] Error: No sectors found in the database. Please add some first.\n");
}

// 2. Loop through every sector
foreach ($sectors as $sector) {
    // Note: Assuming your Sector entity has getName(). If it throws an error, change to getSectorName()
    $industryName = $sector->getName();
    $encodedIndustry = urlencode($industryName);

    echo "[" . date('Y-m-d H:i:s') . "] Fetching news for '{$industryName}'...\n";

    $url = "https://api.marketaux.com/v1/news/all?industries={$encodedIndustry}&language=en&api_token={$apiToken}";

    // Use @ to suppress PHP warnings if the API fails, we handle it gracefully below
    $response = @file_get_contents($url);

    if ($response === false) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: Failed to connect to Marketaux API for {$industryName}. Skipping...\n";
        continue; // Skip to the next sector instead of crashing the whole script
    }

    $data = json_decode($response, true);

    if (!isset($data['data'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: Invalid API response for {$industryName}. Skipping...\n";
        continue;
    }

    $articlesAdded = 0;
    $fetchedUuids = []; // We will store the UUIDs of the current fetch here

    // 3. Process and save to Doctrine
    foreach ($data['data'] as $articleData) {
        $uuid = $articleData['uuid'];
        $fetchedUuids[] = $uuid; // Add to our tracking list

        $existingArticle = $newsRepository->findOneBy(['uuid' => $uuid]);

        if (!$existingArticle) {
            try {
                $publishedAt = new DateTimeImmutable($articleData['published_at']);

                $news = new News(
                    $uuid,
                    $articleData['title'],
                    $articleData['url'],
                    $publishedAt,
                    $sector
                );

                $news->setDescription($articleData['description'] ?? null);
                $news->setImageUrl($articleData['image_url'] ?? null);

                $entityManager->persist($news);
                $articlesAdded++;

            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Failed to parse data for article {$uuid}: " . $e->getMessage() . "\n";
            }
        }
    }

    // Flush new inserts to the database for this specific sector
    try {
        $entityManager->flush();
        echo "[" . date('Y-m-d H:i:s') . "] Successfully added {$articlesAdded} new articles to '{$industryName}'.\n";
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Database Error during insert: " . $e->getMessage() . "\n";
    }

    // 4. Clean up old news (Delete anything NOT in the current fetch)
    if (!empty($fetchedUuids)) {
        try {
            $qb = $entityManager->createQueryBuilder();
            $qb->delete(News::class, 'n')
                ->where('n.sector = :sector')
                ->andWhere($qb->expr()->notIn('n.uuid', ':uuids'))
                ->setParameter('sector', $sector)
                ->setParameter('uuids', $fetchedUuids);

            $deletedCount = $qb->getQuery()->execute();
            echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$deletedCount} outdated articles for '{$industryName}'.\n";
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Error cleaning old articles: " . $e->getMessage() . "\n";
        }
    }

    // Politeness delay: Sleep for 1 second so Marketaux doesn't block you for spamming requests
    sleep(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Global news fetch sequence complete!\n";