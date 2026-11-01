<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Team407\InvestingWebsite\Entity\Forum;
use Team407\InvestingWebsite\Entity\Subforum;
use Team407\InvestingWebsite\Entity\User;

require_once __DIR__ . "/../vendor/autoload.php";

function userToForumAuthorData(?User $user): array
{
    if (!$user) {
        return [
            "user_id" => null,
            "username" => "User",
            "sector_id" => null,
            "sector_name" => null,
            "profile_picture" => null,
            "admin" => false,
            "suspended_until" => null
        ];
    }

    $sector = $user->getSector();
    $isAdmin = false;

    if (method_exists($user, "isAdmin")) {
        $isAdmin = $user->isAdmin();
    }

    $suspendedUntil = null;

    if (method_exists($user, "getSuspendedUntil") && $user->getSuspendedUntil()) {
        $suspendedUntil = $user->getSuspendedUntil()->format("Y-m-d H:i:s");
    }

    return [
        "user_id" => $user->getUserId(),
        "username" => $user->getUsername(),
        "sector_id" => $sector ? $sector->getSectorId() : null,
        "sector_name" => $sector ? $sector->getSectorName() : null,
        "profile_picture" => $user->getProfilePicture(),
        "admin" => $isAdmin,
        "suspended_until" => $suspendedUntil
    ];
}

function buildForumListResponseData(EntityManagerInterface $entityManager, array $forums): array
{
    $forumData = [];

    foreach ($forums as $forum) {
        $forumOwner = $forum->getUser();
        $forumOwnerData = userToForumAuthorData($forumOwner);

        $subforums = $entityManager->getRepository(Subforum::class)->findBy(
            ["forum" => $forum],
            ["createdAt" => "ASC"]
        );

        $responseData = [];

        foreach ($subforums as $subforum) {
            $responseUser = $subforum->getUser();
            $responseUserData = userToForumAuthorData($responseUser);

            $responseData[] = [
                "subforum_id" => $subforum->getSubforumId(),
                "response" => $subforum->getResponse(),
                "created_at" => $subforum->getCreatedAt()
                    ? $subforum->getCreatedAt()->format("Y-m-d H:i:s")
                    : null,
                "forum_id" => $forum->getForumId(),
                "user_id" => $responseUserData["user_id"],
                "username" => $responseUserData["username"],
                "sector_id" => $responseUserData["sector_id"],
                "sector_name" => $responseUserData["sector_name"],
                "profile_picture" => $responseUserData["profile_picture"],
                "admin" => $responseUserData["admin"],
                "suspended_until" => $responseUserData["suspended_until"]
            ];
        }

        $forumData[] = [
            "forum_id" => $forum->getForumId(),
            "title" => $forum->getTitle(),
            "question" => $forum->getQuestion(),
            "created_at" => $forum->getCreatedAt()
                ? $forum->getCreatedAt()->format("Y-m-d H:i:s")
                : null,
            "user_id" => $forumOwnerData["user_id"],
            "username" => $forumOwnerData["username"],
            "sector_id" => $forumOwnerData["sector_id"],
            "sector_name" => $forumOwnerData["sector_name"],
            "profile_picture" => $forumOwnerData["profile_picture"],
            "admin" => $forumOwnerData["admin"],
            "suspended_until" => $forumOwnerData["suspended_until"],
            "response_count" => count($responseData),
            "responses" => $responseData
        ];
    }

    return $forumData;
}

function normalizeForumListOptions(array $query): array
{
    $search = trim((string)($query["search"] ?? ""));
    $limit = (int)($query["limit"] ?? 10);
    $sort = trim((string)($query["sort"] ?? "newest"));

    if (!in_array($limit, [10, 20, 50], true)) {
        $limit = 10;
    }

    $allowedSorts = [
        "newest" => ["f.createdAt", "DESC"],
        "oldest" => ["f.createdAt", "ASC"],
        "title_az" => ["f.title", "ASC"],
        "title_za" => ["f.title", "DESC"],
        "username_az" => ["u.username", "ASC"]
    ];

    if (!array_key_exists($sort, $allowedSorts)) {
        $sort = "newest";
    }

    return [$search, $limit, $sort, $allowedSorts[$sort]];
}

function handleGetForumsRequest(EntityManagerInterface $entityManager, array $query): array
{
    [$search, $limit, $sort, [$sortField, $sortDirection]] = normalizeForumListOptions($query);

    $qb = $entityManager->createQueryBuilder();

    $qb->select("f", "u", "s")
        ->from(Forum::class, "f")
        ->leftJoin("f.user", "u")
        ->leftJoin("u.sector", "s")
        ->orderBy($sortField, $sortDirection)
        ->setMaxResults($limit);

    if ($sortField !== "f.createdAt") {
        $qb->addOrderBy("f.createdAt", "DESC");
    }

    if ($search !== "") {
        $qb->where(
            $qb->expr()->orX(
                $qb->expr()->like("LOWER(f.title)", ":search"),
                $qb->expr()->like("LOWER(f.question)", ":search"),
                $qb->expr()->like("LOWER(u.username)", ":search"),
                $qb->expr()->like("LOWER(s.sectorName)", ":search")
            )
        );

        $qb->setParameter("search", "%" . strtolower($search) . "%");
    }

    $forums = $qb->getQuery()->getResult();

    return [200, [
        "success" => true,
        "forums" => buildForumListResponseData($entityManager, $forums),
        "sort" => $sort,
        "limit" => $limit
    ]];
}

function runGetForumsEndpoint(): void
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
        [$status, $response] = handleGetForumsRequest($entityManager, $_GET);

        http_response_code($status);
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
    runGetForumsEndpoint();
}
