<?php
header("Content-Type: application/json");

include '../config.php';

$memcache = new Memcached();
$memcache->addServer('localhost', 11211);

$project = isset($_GET['project']) ? $conn->real_escape_string($_GET['project']) : '';
$event_id = isset($_GET['event_id']) ? $conn->real_escape_string($_GET['event_id']) : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';

$eventCacheKey = "event_{$event_id}";
$statsCacheKey = "stats_{$event_id}_{$project}";

if ($action === 'purge') {
    $memcache->delete($statsCacheKey);
    echo json_encode(['message' => 'Statistics cache purged']);
    exit;
}

$eventData = $memcache->get($eventCacheKey);
if (!$eventData) {
    $eventUrl = "https://meta.wikimedia.org/w/rest.php/campaignevents/v0/event_registration/{$event_id}";
    $eventData = file_get_contents($eventUrl);
    $event = json_decode($eventData, true);

    if (!$event || !isset($event['start_time'], $event['end_time'], $event['timezone'], $event['wikis'])) {
        echo json_encode(['error' => 'Failed to fetch event data']);
        exit;
    }

    $startTimestamp = strtotime($event['start_time']);
    $endTimestamp = strtotime($event['end_time']);
    $cacheDuration = $endTimestamp - time();

    if ($cacheDuration > 0) {
        $memcache->set($eventCacheKey, json_encode($event), $cacheDuration);
    }
} else {
    $event = json_decode($eventData, true);
}

if (!in_array($project, $event['wikis'])) {
    echo json_encode(['error' => 'This event is not available for this wiki']);
    exit;
}

$timezone = $event['timezone'];
$start_date = $event['start_time'];
$end_date = $event['end_time'];

// Obtener lista de participantes con paginación
$participants = [];
$last_participant_id = null;
do {
    $participantsUrl = "https://meta.wikimedia.org/w/rest.php/campaignevents/v0/event_registration/{$event_id}/participants";
    $params = ['include_private' => 'false'];
    if ($last_participant_id) {
        $params['last_participant_id'] = $last_participant_id;
    }
    
    $participantsApiUrl = $participantsUrl . '?' . http_build_query($params);
    $participantsData = file_get_contents($participantsApiUrl);
    $batch = json_decode($participantsData, true);
    
    if ($batch && is_array($batch)) {
        $participants = array_merge($participants, $batch);
        if (count($batch) == 20) {
            $last_participant_id = end($batch)['participant_id'];
        } else {
            break;
        }
    } else {
        break;
    }
} while (true);

$participantUsernames = array_map(function($p) use ($conn) {
    return "'" . $conn->real_escape_string($p['user_name']) . "'";
}, $participants);

$participantCondition = empty($participantUsernames) ? "1=0" : "a.creator_username IN (" . implode(',', $participantUsernames) . ")";

$cachedStats = $memcache->get($statsCacheKey);
if (!$cachedStats) {
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
              AND ($participantCondition)
    ";

    $result = $conn->query($sql);
    $data = $result->fetch_assoc() ?? [
        'totalPeople' => 0,
        'totalWomen' => 0,
        'totalMen' => 0,
        'otherGenders' => 0,
        'lastUpdated' => null
    ];
    
    $memcache->set($statsCacheKey, json_encode($data), 3600);
} else {
    $data = json_decode($cachedStats, true);
}

$response = [
    'event' => [
        'id' => $event['id'],
        'name' => $event['name'],
        'event_page' => $event['event_page'],
        'status' => $event['status'],
        'timezone' => $event['timezone'],
        'start_time' => $event['start_time'],
        'end_time' => $event['end_time'],
        'wikis' => $event['wikis'],
        'topics' => $event['topics'],
    ],
    'totalPeople' => (int)$data['totalPeople'],
    'totalWomen' => (int)$data['totalWomen'],
    'totalMen' => (int)$data['totalMen'],
    'otherGenders' => (int)$data['otherGenders'],
    'lastUpdated' => $data['lastUpdated'],
    'participants' => $participants
];

echo json_encode($response);

$conn->close();
?>
