<?php
header("Content-Type: application/json");

include '../config.php';

$memcache = new Memcached();
$memcache->addServer('localhost', 11211);

$project = isset($_GET['project']) ? $conn->real_escape_string($_GET['project']) : '';
$event_id = isset($_GET['event_id']) ? $conn->real_escape_string($_GET['event_id']) : '';

$eventUrl = "https://meta.wikimedia.org/w/rest.php/campaignevents/v0/event_registration/{$event_id}";
$eventData = file_get_contents($eventUrl);
$event = json_decode($eventData, true);

if (!$event || !isset($event['start_time'], $event['end_time'], $event['timezone'], $event['wikis'])) {
    echo json_encode(['error' => 'Failed to fetch event data']);
    exit;
}

if (!in_array($project, $event['wikis'])) {
    echo json_encode(['error' => 'This event is not available for this wiki']);
    exit;
}

$timezone = $event['timezone'];
$start_date = $event['start_time'];
$end_date = $event['end_time'];

$participantsUrl = "https://meta.wikimedia.org/w/rest.php/campaignevents/v0/event_registration/{$event_id}/participants";
$params = ['include_private' => 'false'];
$participantsApiUrl = $participantsUrl . '?' . http_build_query($params);

$cacheKey = "stats_{$project}_{$event_id}_{$start_date}_{$end_date}";
$cachedResponse = $memcache->get($cacheKey);
$cacheDuration = 21600;

if ($cachedResponse) {
    $response = json_decode($cachedResponse, true);
    $response['executionTime'] = round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2);
    echo json_encode($response);
    exit;
}

$participantsData = file_get_contents($participantsApiUrl);
$participants = json_decode($participantsData, true);

if (!$participants || !is_array($participants)) {
    echo json_encode(['error' => 'Failed to fetch participants']);
    exit;
}

$queries = [];
foreach ($participants as $participant) {
    $username = $conn->real_escape_string($participant['user_name']);
    $userJoinDate = substr($participant['user_registered_at'], 0, 8); // Formato YYYYMMDD
    $formattedJoinDate = substr($userJoinDate, 0, 4) . '-' . substr($userJoinDate, 4, 2) . '-' . substr($userJoinDate, 6, 2);
    $queries[] = "(a.creator_username = '$username' AND a.creation_date >= '$formattedJoinDate')";
}

if (empty($queries)) {
    echo json_encode(['error' => 'No participants found']);
    exit;
}

$condition = implode(' OR ', $queries);

$sql = "
    SELECT COUNT(DISTINCT p.wikidata_id) AS totalPeople,
           SUM(CASE WHEN p.gender = 'Q6581072' THEN 1 ELSE 0 END) AS totalWomen,
           SUM(CASE WHEN p.gender = 'Q6581097' THEN 1 ELSE 0 END) AS totalMen,
           SUM(CASE WHEN p.gender NOT IN ('Q6581072', 'Q6581097') THEN 1 ELSE 0 END) AS otherGenders,
           MAX(w.last_updated) AS lastUpdated
    FROM people p
    JOIN articles a ON p.wikidata_id = a.wikidata_id
    JOIN project w ON a.site = w.site
    WHERE a.site = '{$project}'
          AND a.creation_date BETWEEN '$start_date' AND '$end_date'
          AND ($condition)
";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    if ($data['totalPeople'] == 0) {
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
        $response['executionTime'] = round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2);
        echo json_encode($response);
    }
} else {
    echo json_encode(['error' => 'No data found']);
}

$conn->close();
?>