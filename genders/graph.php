<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

include '../config.php';

// Iniciar Memcached
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);

// Medir tiempo de inicio
$startTime = microtime(true);

// Función para normalizar el parámetro 'project'
function normalizeProject($project) {
    if (preg_match('/^[a-z0-9\-]+(wiki|wikiquote|wikisource)$/', $project)) {
        return $project;
    }

    if (preg_match('/^www\.wikidata\.org$/', $project)) {
        return 'wikidatawiki';
    }

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

// Buscar el proyecto en la base de datos
$sqlProject = "
    SELECT site, name, creation_date
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

// Fechas
$start_date = $_GET['start_date'] ?? $projectData['creation_date'];
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Determinar si se agrupa por día o mes
$start = new DateTime($start_date);
$end = new DateTime($end_date);
$interval = $start->diff($end);
$months_diff = ($interval->y * 12) + $interval->m;
$group_by_day = $months_diff <= 3;

// Generar tabla de calendario
$calendar = [];
$current = clone $start;
while ($current <= $end) {
    if ($group_by_day) {
        $calendar[] = [
            'year' => (int)$current->format('Y'),
            'month' => (int)$current->format('m'),
            'day' => (int)$current->format('d'),
        ];
        $current->modify('+1 day');
    } else {
        $calendar[] = [
            'year' => (int)$current->format('Y'),
            'month' => (int)$current->format('m'),
        ];
        $current->modify('+1 month');
    }
}

// Clave de caché
$cacheKey = "graph_{$project}_{$start_date}_{$end_date}";
$cacheDuration = 21600; // 6 horas

// Verificar caché
$cachedResponse = $memcache->get($cacheKey);
if ($cachedResponse) {
    $response = json_decode($cachedResponse, true);
    $response['executionTime'] = round((microtime(true) - $startTime) * 1000, 2);
    echo json_encode($response);
    exit;
}

// Consulta SQL
if ($project === 'all') {
    $whereClause = "a.creation_date >= '$start_date' AND a.creation_date <= '$end_date'";
} else {
    $whereClause = "a.site = '{$project}' AND a.creation_date >= '$start_date' AND a.creation_date <= '$end_date'";
}

$groupClause = $group_by_day ?
    "GROUP BY YEAR(a.creation_date), MONTH(a.creation_date), DAY(a.creation_date)" :
    "GROUP BY YEAR(a.creation_date), MONTH(a.creation_date)";

$selectClause = $group_by_day ?
    "YEAR(a.creation_date) AS year,
     MONTH(a.creation_date) AS month,
     DAY(a.creation_date) AS day" :
    "YEAR(a.creation_date) AS year,
     MONTH(a.creation_date) AS month";

$sql = "
    SELECT
        $selectClause,
        COUNT(*) AS total,
        SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
        SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
        SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') OR p.gender IS NULL THEN 1 ELSE 0 END) AS otherGenders
    FROM articles a
    JOIN project w ON a.site = w.site
    LEFT JOIN people p ON a.wikidata_id = p.wikidata_id
    WHERE $whereClause
    $groupClause
";

$result = $conn->query($sql);

// Organizar datos
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'year' => (int)$row['year'],
        'month' => (int)$row['month'],
        'day' => isset($row['day']) ? (int)$row['day'] : null,
        'total' => (int)$row['total'],
        'totalWomen' => (int)$row['totalWomen'],
        'totalMen' => (int)$row['totalMen'],
        'otherGenders' => (int)$row['otherGenders'],
    ];
}

// Combinar calendario con resultados
$combined_data = [];
foreach ($calendar as $date) {
    $match = false;
    foreach ($data as $row) {
        if ($group_by_day) {
            if ($row['year'] == $date['year'] && $row['month'] == $date['month'] && $row['day'] == $date['day']) {
                $combined_data[] = $row;
                $match = true;
                break;
            }
        } else {
            if ($row['year'] == $date['year'] && $row['month'] == $date['month']) {
                $combined_data[] = $row;
                $match = true;
                break;
            }
        }
    }

    if (!$match) {
        $combined_data[] = [
            'year' => $date['year'],
            'month' => $date['month'],
            'day' => $group_by_day ? $date['day'] : null,
            'total' => 0,
            'totalWomen' => 0,
            'totalMen' => 0,
            'otherGenders' => 0,
        ];
    }
}

// Respuesta final
$response = [
    'data' => $combined_data,
];
$memcache->set($cacheKey, json_encode($response), $cacheDuration);

$response['executionTime'] = round((microtime(true) - $startTime) * 1000, 2);
echo json_encode($response);

$conn->close();
