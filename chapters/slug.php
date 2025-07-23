<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1); 
error_reporting(E_ALL); 

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once '../config.php';

if (!isset($_GET['slug']) || empty($_GET['slug'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing chapter slug"]);
    exit;
}

$slug = $conn->real_escape_string($_GET['slug']);

// Opcional: usar Memcached para cachear
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);
$cacheKey = "chapter_detail_" . $slug;
$cacheDuration = 3600;

$cachedData = $memcache->get($cacheKey);
if ($cachedData) {
    echo $cachedData;
    exit;
}

$sql = "
SELECT
    c.id,
    c.slug,
    c.name,
    c.description,
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

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();

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
        "stats" => null // puedes añadir estadísticas futuras aquí
    ];

    $json = json_encode($chapter, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $memcache->set($cacheKey, $json, $cacheDuration);
    echo $json;
} else {
    http_response_code(404);
    echo json_encode(["error" => "Chapter not found"]);
}

$conn->close();
