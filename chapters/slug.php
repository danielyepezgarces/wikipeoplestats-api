<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once '../config.php';

// Verificar si es una acción de purge
if (isset($_GET['action']) && $_GET['action'] === 'purge') {
    if (!isset($_GET['slug']) || empty($_GET['slug'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing chapter slug for purge"]);
        exit;
    }
    
    $slug = $_GET['slug'];
    $wiki = isset($_GET['wiki']) ? $_GET['wiki'] : 'wikidatawiki';
    
    // Inicializar Memcached
    $memcache = new Memcached();
    $memcache->addServer('localhost', 11211);
    
    // Generar la clave de cache
    $cacheKey = "chapter_detail_" . $slug . "_" . md5($wiki);
    
    // Eliminar de la cache
    $purgeResult = $memcache->delete($cacheKey);
    
    if ($purgeResult) {
        echo json_encode([
            "success" => true,
            "message" => "Cache purged successfully",
            "cache_key" => $cacheKey,
            "slug" => $slug,
            "wiki" => $wiki
        ]);
    } else {
        // También consideramos éxito si la clave no existía
        $resultCode = $memcache->getResultCode();
        if ($resultCode == Memcached::RES_NOTFOUND) {
            echo json_encode([
                "success" => true,
                "message" => "Cache key was not found (already cleared or never existed)",
                "cache_key" => $cacheKey,
                "slug" => $slug,
                "wiki" => $wiki
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                "error" => "Failed to purge cache",
                "cache_key" => $cacheKey,
                "memcached_error" => $memcache->getResultMessage()
            ]);
        }
    }
    exit;
}

// Código original para obtener datos del chapter
if (!isset($_GET['slug']) || empty($_GET['slug'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing chapter slug"]);
    exit;
}

// Obtener parámetros
$slug = $conn->real_escape_string($_GET['slug']);
$wiki = isset($_GET['wiki']) ? $conn->real_escape_string($_GET['wiki']) : 'wikidatawiki'; // Valor por defecto

// Cache
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);
$cacheKey = "chapter_detail_" . $slug . "_" . md5($wiki);
$cacheDuration = 3600;

$cachedData = $memcache->get($cacheKey);
if ($cachedData) {
    echo $cachedData;
    exit;
}

// Consulta principal del chapter
$chapterSql = "
SELECT
    c.id,
    c.slug,
    c.name,
    c.avatar_url,
    c.banner_url,
    c.banner_credits,
    c.created_at,
    (
        SELECT u.username
        FROM user_roles ur
        JOIN users u ON u.id = ur.user_id
        WHERE ur.chapter_id = c.id AND ur.role_id = 3
        LIMIT 1
    ) AS admin_name,
    (
        SELECT COUNT(*) 
        FROM chapter_membership cm 
        WHERE cm.chapter_id = c.id
    ) AS members_count
FROM chapters c
WHERE c.slug = '$slug' AND c.status = 'active'
LIMIT 1;
";

$result = $conn->query($chapterSql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $chapterId = $row['id'];
    $chapterCreationDate = $row['created_at'];

    // Consulta de estadísticas con filtro por wiki
    $statsSql = "
    SELECT
        COUNT(DISTINCT p.wikidata_id) AS totalPeople,
        SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
        SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
        SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') THEN 1 ELSE 0 END) AS otherGenders,
        MAX(w.last_updated) AS last_updated
    FROM people p
    JOIN articles a ON p.wikidata_id = a.wikidata_id
    JOIN project w ON a.site = w.site
    JOIN users u ON u.username = a.creator_username
    JOIN chapter_membership cm ON cm.user_id = u.id
    WHERE cm.chapter_id = $chapterId
    AND a.site = '$wiki'  -- Filtro por wiki/project
    AND a.creation_date >= '$chapterCreationDate'
    AND a.creation_date <= CURDATE()
    AND a.creation_date >= cm.joined_at
    ";

    $statsResult = $conn->query($statsSql);
    $statsData = $statsResult ? $statsResult->fetch_assoc() : [
        'totalPeople' => 0,
        'totalWomen' => 0,
        'totalMen' => 0,
        'otherGenders' => 0,
        'last_updated' => null
    ];

    // Consulta de miembros con sus contribuciones en este wiki
    $membersSql = "
    SELECT
        u.id,
        u.username,
        u.email,
        cm.joined_at,
        (
            SELECT COUNT(DISTINCT a.wikidata_id)
            FROM articles a
            WHERE a.creator_username = u.username
            AND a.site = '$wiki'
            AND a.creation_date >= cm.joined_at
        ) AS contributions_count
    FROM users u
    JOIN chapter_membership cm ON cm.user_id = u.id
    WHERE cm.chapter_id = $chapterId
    ORDER BY cm.joined_at DESC
    ";

    $membersResult = $conn->query($membersSql);
    $members = [];
    while ($member = $membersResult->fetch_assoc()) {
        $members[] = [
            "id" => $member['id'],
            "username" => $member['username'],
            "email" => $member['email'],
            "joined_at" => $member['joined_at'],
            "contributions_count" => (int)$member['contributions_count']
        ];
    }

    // Construir respuesta final
    $chapter = [
        "slug" => $row["slug"],
        "group_name" => $row["name"],
        "admin_name" => $row["admin_name"] ?? null,
        "members_count" => (int)$row["members_count"],
        "group_description" => $row["description"] ?? null,
        "creation_date" => $row["created_at"],
        "banner_image" => $row["banner_url"],
        "avatar_image" => $row["avatar_url"],
        "image_credit" => $row["banner_credits"],
        "members" => $members,
        "stats" => [
            "totalPeople" => (int)$statsData['totalPeople'],
            "totalWomen" => (int)$statsData['totalWomen'],
            "totalMen" => (int)$statsData['totalMen'],
            "otherGenders" => (int)$statsData['otherGenders'],
            "last_updated" => $statsData['last_updated']
        ],
        "wiki" => $wiki  // Añadido para referencia
    ];

    $json = json_encode($chapter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $memcache->set($cacheKey, $json, $cacheDuration);
    echo $json;
} else {
    http_response_code(404);
    echo json_encode(["error" => "Chapter not found"]);
}

$conn->close();