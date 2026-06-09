<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Event;
use App\Support\Database;
use App\Support\Url;

header('Content-Type: application/xml; charset=utf-8');

$pdo = Database::connection();
$eventsModel = new Event($pdo);
$events = $eventsModel->all();

echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
echo '<rss version="2.0">' . "\n";
echo '  <channel>' . "\n";
echo '    <title>Local Greetings Events Feed</title>' . "\n";
echo '    <link>' . htmlspecialchars(Url::appUrl('frontend/index.html')) . '</link>' . "\n";
echo '    <description>Latest sports events in Iasi</description>' . "\n";
echo '    <language>en-us</language>' . "\n";

foreach ($events as $event) {
    $title = htmlspecialchars($event['title']);
    $description = htmlspecialchars($event['description'] . ' - at ' . $event['location']['name'] . ' (' . $event['location']['area'] . ') on ' . $event['eventDate']);
    $link = htmlspecialchars(Url::appUrl("frontend/event-details.html?id=" . $event['id']));
    $guid = $event['id'];
    $pubDate = date(DATE_RSS, strtotime($event['createdAt']));

    echo '    <item>' . "\n";
    echo '      <title>' . $title . '</title>' . "\n";
    echo '      <description>' . $description . '</description>' . "\n";
    echo '      <link>' . $link . '</link>' . "\n";
    echo '      <guid isPermaLink="false">' . $guid . '</guid>' . "\n";
    echo '      <pubDate>' . $pubDate . '</pubDate>' . "\n";
    echo '    </item>' . "\n";
}

echo '  </channel>' . "\n";
echo '</rss>' . "\n";