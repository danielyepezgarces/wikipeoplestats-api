<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, OPTIONS");

include '../config.php';

// Iniciar Memcached
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);

// Medir tiempo de inicio
$startTime = microtime(true);

// Función para normalizar el parámetro 'project'
function normalizeProject($project) {
    // Si ya está en formato correcto, lo dejamos
    if (preg_match('/^[a-z0-9\-]+(wiki|wikiquote|wikisource)$/', $project)) {
        return $project;
    }

    // www.wikidata.org → wikidatawiki
    if (preg_match('/^www\.wikidata\.org$/', $project)) {
        return 'wikidatawiki';
    }

    // es.wikipedia.org → eswiki
    // fr.wikiquote.org → frwikiquote
    // de.wikisource.org → dewikisource
    if (preg_match('/^([a-z0-9\-]+)\.(wikipedia|wikiquote|wikisource)(\.org)?$/', $project, $matches)) {
        $lang = $matches[1];
        $type = $matches[2];

        $suffix = match ($type) {
            'wikipedia' => 'wiki',
            'wikiquote' => 'wikiquote',
            'wikisource' => 'wikisource',
            default => 'wiki'
        };

        return $lang . $suffix;
    }

    return $project;
}

// Obtener y normalizar el parámetro 'project'
$projectRaw = $_GET['project'] ?? '';
$normalizedProject = normalizeProject($projectRaw);
$project = $conn->real_escape_string($normalizedProject);

// Consultar el proyecto en la base de datos
$sqlProject = "
    SELECT site, name, `group`, last_updated, creation_date
    FROM project
    WHERE site = '$project'
    LIMIT 1
";

$projectResult = $conn->query($sqlProject);

if ($projectResult->num_rows === 0) {
    echo json_encode(['error' => 'Project not found']);
    exit;
}

$projectData = $projectResult->fetch_assoc();

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Definir la clave de caché
$cacheKey = "wikistats_{$projectData['site']}_{$start_date}_{$end_date}";

if (isset($_GET['action']) && $_GET['action'] === 'purge') {
    $memcache->delete($cacheKey);
    echo json_encode(['message' => 'Cache purged successfully.']);
    exit;
}

// Comprobar si la respuesta está en caché
$cachedResponse = $memcache->get($cacheKey);
$cacheDuration = 43200; // 6 horas

if ($cachedResponse) {
    $response = json_decode($cachedResponse, true);
    $executionTime = microtime(true) - $startTime;
    $response['executionTime'] = round($executionTime * 1000, 2);
    echo json_encode($response);
    exit;
}

// Si no se proporcionan fechas, usar site_aggregates
if (empty($start_date) && empty($end_date)) {
    $sql = "
        SELECT
            total_people AS totalPeople,
            total_women AS totalWomen,
            total_men AS totalMen,
            other_genders AS otherGenders,
            last_updated AS lastUpdated
        FROM site_aggregates
        WHERE site = '{$projectData['site']}'
        LIMIT 1
    ";
} else {
    // Si solo falta una de las fechas
    if (empty($start_date)) {
        $start_date = $projectData['creation_date'] ?? '2001-01-01';
    }
    if (empty($end_date)) {
        $end_date = date('Y-m-d');
    }

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
        WHERE a.creation_date >= '$start_date'
            AND a.creation_date <= '$end_date'
            AND a.site = '{$projectData['site']}'
    ";
}

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();

    if (
        $data['totalPeople'] == 0 &&
        $data['totalWomen'] == 0 &&
        $data['totalMen'] == 0 &&
        $data['otherGenders'] == 0
    ) {
        echo json_encode(['error' => 'No data found']);
    } else {
        $response = [
            'totalPeople' => (int)$data['totalPeople'],
            'totalWomen' => (int)$data['totalWomen'],
            'totalMen' => (int)$data['totalMen'],
            'otherGenders' => (int)$data['otherGenders'],
            'lastUpdated' => $data['lastUpdated'] ?? null,
        ];

        $memcache->set($cacheKey, json_encode($response), $cacheDuration);

        $executionTime = microtime(true) - $startTime;
        $response['executionTime'] = round($executionTime * 1000, 2);

        echo json_encode($response);
    }
} else {
    echo json_encode(['error' => 'No data found']);
}

$conn->close();
?>
