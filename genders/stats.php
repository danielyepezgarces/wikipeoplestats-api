<?php
// Encabezados CORS
header('Content-Type: application/json');

// Preflight para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Includes y configuración
include '../config.php';
include '../languages.php';

// Asumimos que $wiki está definido correctamente
$wikiId = $wiki['wiki'];
$creationDate = $wiki['creation_date'];

// Escapar fechas
$start_date = $conn->real_escape_string($_GET['start_date'] ?? $creationDate);
$end_date = $conn->real_escape_string($_GET['end_date'] ?? date('Y-m-d'));
$isFullRange = ($start_date === $creationDate && $end_date === date('Y-m-d'));

$cacheKey = "wikistats_{$wikiId}_{$start_date}_{$end_date}";

// 🔁 Purgar caché si se solicita
if (isset($_GET['action']) && $_GET['action'] === 'purge') {
    if ($memcached) {
        $memcached->delete($cacheKey);
    }
    echo json_encode(['message' => 'Cache purged successfully.']);
    exit;
}

// ⏱️ Verificar caché
if ($memcached) {
    $cachedResult = $memcached->get($cacheKey);
    if ($cachedResult !== false) {
        echo json_encode($cachedResult);
        exit;
    }
}

// 📊 Construir consulta
if ($isFullRange) {
    $sql = "
        SELECT
            total_people AS totalPeople,
            total_women AS totalWomen,
            total_men AS totalMen,
            other_genders AS otherGenders,
            last_updated AS lastUpdated
        FROM site_aggregates
        WHERE site = '{$conn->real_escape_string($wikiId)}'
        LIMIT 1
    ";
} else {
    $sql = "
        SELECT
            COUNT(DISTINCT a.wikidata_id) AS totalPeople,
            COUNT(DISTINCT CASE WHEN p.gender = 'Q6581072' THEN a.wikidata_id END) AS totalWomen,
            COUNT(DISTINCT CASE WHEN p.gender = 'Q6581097' THEN a.wikidata_id END) AS totalMen,
            COUNT(DISTINCT CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN a.wikidata_id END) AS otherGenders,
            COUNT(DISTINCT a.creator_username) AS totalContributions,
            MAX(w.last_updated) AS lastUpdated
        FROM articles a
        LEFT JOIN people p ON p.wikidata_id = a.wikidata_id
        JOIN project w ON a.site = w.site
        WHERE a.creation_date BETWEEN '{$start_date}' AND '{$end_date}'
          AND a.site = '{$conn->real_escape_string($wikiId)}'
    ";
}

// 🧾 Ejecutar consulta
$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la consulta: ' . $conn->error]);
    exit;
}

$data = $result->fetch_assoc();

// 💾 Guardar en caché por 12 horas
if ($memcached && $data) {
    $memcached->set($cacheKey, $data, 43200); // 12h en segundos
}

// 📤 Respuesta final
echo json_encode($data);
