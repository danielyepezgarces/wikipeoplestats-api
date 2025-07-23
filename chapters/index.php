<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once '../config.php';

// Opcional: usar Memcached para cachear
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);
$cacheKey = "chapters_list_v2";
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
WHERE c.status = 'active'
ORDER BY c.created_at DESC;
";

$result = $conn->query($sql);
$chapters = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $chapters[] = [
            "slug" => $row["slug"],
            "group_name" => $row["name"],
            "admin_name" => $row["admin_name"] ?? null,
            "members_count" => (int)$row["members_count"],
            "group_description" => null, // No está en la tabla
            "creation_date" => $row["created_at"],
            "banner_image" => $row["banner_url"],
            "avatar_image" => $row["avatar_url"],
            "image_credit" => $row["banner_credits"],
            "stats" => null // En el futuro puedes rellenarlo con otros datos
        ];
    }

    $json = json_encode($chapters, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $memcache->set($cacheKey, $json, $cacheDuration);
    echo $json;
} else {
    echo json_encode(["error" => "No chapters found"]);
}

$conn->close();
