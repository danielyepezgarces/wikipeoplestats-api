<?php
// Encabezados CORS
header("Content-Type: application/json");

include '../config.php';
include '../languages.php';

// Iniciar Memcached
$memcache = new Memcached();
$memcache->addServer('localhost', 11211); // Ajusta si tu host o puerto es distinto

$startTime = microtime(true);

// Obtener y limpiar el parámetro project
$project = $_GET['project'] ?? '';
$project = $conn->real_escape_string($project);

// Normalizar (eliminar extensiones)
$project = str_replace(
    ['.wikipedia.org', '.wikiquote.org', '.wikisource.org', '.wikipedia', '.wikiquote', '.wikisource'],
    '',
    $project
);

// Buscar en el array wikis
$wiki_key = array_search($project, array_column($wikis, 'wiki'));

// Si no está, intenta variantes comunes
if ($wiki_key === false) {
    foreach ([$project, $project . 'wiki', $project . 'wikiquote', $project . 'wikisource'] as $variant) {
        $wiki_key = array_search($variant, array_column($wikis, 'wiki'));
        if ($wiki_key !== false) break;
    }
}

// Si no se encuentra el wiki, abortar
if ($wiki_key === false) {
    echo json_encode(['error' => 'Project not found']);
    exit;
}

$wiki = $wikis[$wiki_key];
$wikiId = $wiki['wiki'];
$creationDate = $wiki['creation_date'];

$start_date = $conn->real_escape_string($_GET['start_date'] ?? $creationDate);
$end_date = $conn->real_escape_string($_GET['end_date'] ?? date('Y-m-d'));

// Clave para Memcached
$cacheKey = "wikistats_{$wikiId}_{$start_date}_{$end_date}";

// Purgar caché si se solicita
if (isset($_GET['action']) && $_GET['action'] === 'purge') {
    $memcache->delete($cacheKey);
    echo json_encode([
        'message' => 'Cache purged successfully.',
        'cacheKey' => $cacheKey,
        'project' => $wikiId,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'executionTime' => round((microtime(true) - $startTime) * 1000, 2)
    ]);
    exit;
}

// Si está en caché, devolverlo
$cachedResponse = $memcache->get($cacheKey);
$cacheDuration = 21600; // 6 horas

if ($cachedResponse) {
    $response = json_decode($cachedResponse, true);
    $response['executionTime'] = round((microtime(true) - $startTime) * 1000, 2);
    echo json_encode($response);
    exit;
}

// Consulta SQL principal
$sql = "
    SELECT
        COUNT(DISTINCT a.wikidata_id) AS totalPeople,
        SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
        SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
        SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN 1 ELSE 0 END) AS otherGenders,
        COUNT(DISTINCT a.creator_username) AS totalContributions,
        MAX(w.last_updated) AS lastUpdated
    FROM articles a
    LEFT JOIN people p ON p.wikidata_id = a.wikidata_id
    JOIN project w ON a.site = w.site
    WHERE a.creation_date BETWEEN '$start_date' AND '$end_date'
      AND a.site = '$wikiId'
";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $data = $result->fetch_assoc();

    if ((int)$data['totalPeople'] === 0) {
        echo json_encode(['error' => 'No data found']);
    } else {
        $response = [
            'totalPeople' => (int)$data['totalPeople'],
            'totalWomen' => (int)$data['totalWomen'],
            'totalMen' => (int)$data['totalMen'],
            'otherGenders' => (int)$data['otherGenders'],
            'lastUpdated' => $data['lastUpdated'] ?: null,
            'executionTime' => round((microtime(true) - $startTime) * 1000, 2)
        ];

        $memcache->set($cacheKey, json_encode($response), $cacheDuration);
        echo json_encode($response);
    }
} else {
    echo json_encode(['error' => 'No data found']);
}

$conn->close();
