<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../vendor/autoload.php';

$entityManager = require_once __DIR__ . '/../bootstrap.php';

use Team407\InvestingWebsite\Entity\SectorInStocks;
use Team407\InvestingWebsite\Entity\User;

$userId = $_GET['user_id'] ?? null;

try {

    $userPreferredId = null;

    if ($userId) {

        $user = $entityManager
            ->getRepository(User::class)
            ->find($userId);

        if ($user && $user->getSector()) {

            $userPreferredId = $user
                ->getSector()
                ->getSectorId();
        }
    }

    $qb = $entityManager->createQueryBuilder();

    $qb->select('sis', 'stock', 'sector')
        ->from(SectorInStocks::class, 'sis')
        ->join('sis.stock', 'stock')
        ->join('sis.sector', 'sector');

    if ($userPreferredId !== null) {

        $qb->where('sector.sectorId = :sectorId')
            ->setParameter('sectorId', $userPreferredId);
    }

    $qb->setMaxResults(4);

    $results = $qb->getQuery()->getResult();

    $response = [];

    foreach ($results as $sis) {

        $stock = $sis->getStock();

        $sector = $sis->getSector();

        $response[] = [
            'symbol' => $stock->getTicker(),
            'name' => $stock->getCompanyName(),
            'sector' => $sector->getSectorName(),
            'price' => "$" . number_format(
                    rand(100, 500) + (rand(0, 99) / 100),
                    2
                ),
            'change' => (
                rand(0, 1) ? "+" : "-"
                ) . number_format(
                    rand(0, 4) + (rand(0, 99) / 100),
                    2
                ) . "%",
            'positive' => true
        ];
    }

    echo json_encode($response);

} catch (\Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "error" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}