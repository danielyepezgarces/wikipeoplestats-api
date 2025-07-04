<?php
include '../config.php';
include '../languages.php';

// Asumimos que $wiki está definido y contiene los datos del sitio
$wikiId = $wiki['wiki'];
$creationDate = $wiki['creation_date'];

// Establecer fechas
$start_date = $_GET['start_date'] ?? $creationDate;
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Determinar si es rango completo
$isFullRange = ($start_date === $creationDate && $end_date === date('Y-m-d'));

// Clave de caché
$cacheKey = "wikistats_{$wikiId}_{$start_date}_{$end_date}";

// Intentar obtener datos desde Memcached
if ($memcached) {
    $cachedResult = $memcached->get($cacheKey);
    if ($cachedResult !== false) {
        echo json_encode($cachedResult);
        exit;
    }
}

// Construir la consulta SQL
if ($isFullRange) {
    // Consulta optimizada desde resumen
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
    // Consulta completa con filtros
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

// Ejecutar la consulta
$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    die(json_encode(['error' => 'Error en la consulta: ' . $conn->error]));
}

$data = $result->fetch_assoc();

// Guardar en caché si aplica
if ($memcached && $data) {
    $memcached->set($cacheKey, $data, 3600); // 1 hora
}

// Devolver respuesta
header('Content-Type: application/json');
echo json_encode($data);
