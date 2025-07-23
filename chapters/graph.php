<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include '../config.php';

// Iniciar Memcached
$memcache = new Memcached();
$memcache->addServer('localhost', 11211);

// Obtener parámetros
$slug = isset($_GET['slug']) ? $conn->real_escape_string($_GET['slug']) : '';
$project = isset($_GET['project']) ? $conn->real_escape_string($_GET['project']) : 'wikidatawiki';
$start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-d');

// Validar parámetros
if (empty($slug)) {
    echo json_encode(['error' => 'Chapter slug is required']);
    exit;
}

// Array de idiomas (el mismo que en tu ejemplo)
$languages = [
    ['code' => 'all', 'name' => 'All Wikipedias', 'flag' => '🌐', 'date_format' => 'l, F j, Y', 'wiki' => 'globalwiki', 'creation_date' => '2001-01-15'],
    // ... (otros idiomas como en tu ejemplo)
];

// Buscar el código de idioma
$language_code = array_search($project, array_column($languages, 'wiki'));

if ($language_code === false) {
    echo json_encode(['error' => 'Invalid project']);
    exit;
}

// Obtener información básica del chapter
$chapterSql = "SELECT id, created_at FROM chapters WHERE slug = '$slug' AND status = 'active' LIMIT 1";
$chapterResult = $conn->query($chapterSql);

if (!$chapterResult || $chapterResult->num_rows === 0) {
    echo json_encode(['error' => 'Chapter not found']);
    exit;
}

$chapter = $chapterResult->fetch_assoc();
$chapterId = $chapter['id'];
$chapterCreationDate = $chapter['created_at'];

// Si no se proporciona start_date, usar la fecha de creación del chapter o la de la wiki (la más reciente)
if (empty($start_date)) {
    $wikiCreationDate = $languages[$language_code]['creation_date'];
    $start_date = max($chapterCreationDate, $wikiCreationDate);
}

// Generar calendario para el rango de fechas
$start_year = (int)date('Y', strtotime($start_date));
$start_month = (int)date('m', strtotime($start_date));
$end_year = (int)date('Y', strtotime($end_date));
$end_month = (int)date('m', strtotime($end_date));

$calendar = [];
for ($year = $start_year; $year <= $end_year; $year++) {
    $start_month_in_year = ($year == $start_year) ? $start_month : 1;
    $end_month_in_year = ($year == $end_year) ? $end_month : 12;
    for ($month = $start_month_in_year; $month <= $end_month_in_year; $month++) {
        $calendar[] = [
            'year' => $year,
            'month' => $month,
        ];
    }
}

// Clave de caché única
$cacheKey = "chapter_graph_{$slug}_{$project}_{$start_date}_{$end_date}";
$cacheDuration = 21600; // 6 horas

// Intentar obtener de caché
$cachedResponse = $memcache->get($cacheKey);
if ($cachedResponse) {
    $response = json_decode($cachedResponse, true);
    $response['executionTime'] = round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2);
    echo json_encode($response);
    exit;
}

// Consulta para obtener estadísticas agrupadas por mes
if ($language_code === 'all') {
    $sql = "
        SELECT
            YEAR(a.creation_date) AS year,
            MONTH(a.creation_date) AS month,
            COUNT(DISTINCT a.wikidata_id) AS total,
            SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
            SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
            SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') THEN 1 ELSE 0 END) AS otherGenders,
            COUNT(DISTINCT a.creator_username) AS active_editors
        FROM people p
        JOIN articles a ON p.wikidata_id = a.wikidata_id
        JOIN project w ON a.site = w.site
        JOIN users u ON u.username = a.creator_username
        JOIN chapter_membership cm ON cm.user_id = u.id
        WHERE cm.chapter_id = $chapterId
        AND a.creation_date >= '$start_date'
        AND a.creation_date <= '$end_date'
        AND a.creation_date >= cm.joined_at
        GROUP BY YEAR(a.creation_date), MONTH(a.creation_date)
    ";
} else {
    $sql = "
        SELECT
            YEAR(a.creation_date) AS year,
            MONTH(a.creation_date) AS month,
            COUNT(DISTINCT a.wikidata_id) AS total,
            SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
            SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
            SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') THEN 1 ELSE 0 END) AS otherGenders,
            COUNT(DISTINCT a.creator_username) AS active_editors
        FROM people p
        JOIN articles a ON p.wikidata_id = a.wikidata_id
        JOIN project w ON a.site = w.site
        JOIN users u ON u.username = a.creator_username
        JOIN chapter_membership cm ON cm.user_id = u.id
        WHERE cm.chapter_id = $chapterId
        AND a.site = '{$languages[$language_code]['wiki']}'
        AND a.creation_date >= '$start_date'
        AND a.creation_date <= '$end_date'
        AND a.creation_date >= cm.joined_at
        GROUP BY YEAR(a.creation_date), MONTH(a.creation_date)
    ";
}

$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = [
        'year' => (int)$row['year'],
        'month' => (int)$row['month'],
        'total' => (int)$row['total'],
        'totalWomen' => (int)$row['totalWomen'],
        'totalMen' => (int)$row['totalMen'],
        'otherGenders' => (int)$row['otherGenders'],
        'active_editors' => (int)$row['active_editors']
    ];
}

// Combinar con el calendario para incluir meses sin actividad
$combined_data = [];
foreach ($calendar as $date) {
    $match = false;
    foreach ($data as $row) {
        if ($row['year'] == $date['year'] && $row['month'] == $date['month']) {
            $combined_data[] = $row;
            $match = true;
            break;
        }
    }
    if (!$match) {
        $combined_data[] = [
            'year' => $date['year'],
            'month' => $date['month'],
            'total' => 0,
            'totalWomen' => 0,
            'totalMen' => 0,
            'otherGenders' => 0,
            'active_editors' => 0
        ];
    }
}

// Consulta para estadísticas totales (resumen)
$totalsSql = "
    SELECT
        COUNT(DISTINCT p.wikidata_id) AS totalPeople,
        SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
        SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
        SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') THEN 1 ELSE 0 END) AS otherGenders,
        COUNT(DISTINCT a.creator_username) AS totalEditors,
        MAX(w.last_updated) AS last_updated
    FROM people p
    JOIN articles a ON p.wikidata_id = a.wikidata_id
    JOIN project w ON a.site = w.site
    JOIN users u ON u.username = a.creator_username
    JOIN chapter_membership cm ON cm.user_id = u.id
    WHERE cm.chapter_id = $chapterId
    AND a.creation_date >= '$start_date'
    AND a.creation_date <= '$end_date'
    AND a.creation_date >= cm.joined_at
    " . ($language_code !== 'all' ? "AND a.site = '{$languages[$language_code]['wiki']}'" : "");

$totalsResult = $conn->query($totalsSql);
$totals = $totalsResult->fetch_assoc();

// Construir respuesta
$response = [
    'slug' => $slug,
    'project' => $project,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'timeline' => $combined_data,
    'totals' => [
        'totalPeople' => (int)$totals['totalPeople'],
        'totalWomen' => (int)$totals['totalWomen'],
        'totalMen' => (int)$totals['totalMen'],
        'otherGenders' => (int)$totals['otherGenders'],
        'totalEditors' => (int)$totals['totalEditors'],
        'last_updated' => $totals['last_updated']
    ]
];

// Almacenar en caché
$memcache->set($cacheKey, json_encode($response), $cacheDuration);

// Tiempo de ejecución
$response['executionTime'] = round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2);

echo json_encode($response);
$conn->close();