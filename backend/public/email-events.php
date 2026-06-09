<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Event;
use App\Models\User;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Mailer;
use App\Support\Response;

$pdo = Database::connection();
$userModel = new User($pdo);
$user = Auth::user($userModel);

if (!$user) {
    Response::json(['message' => 'Unauthorized'], 401);
}

$eventsModel = new Event($pdo);
$events = $eventsModel->forUserInterests($user['id']);

if (empty($events)) {
    Response::json(['message' => 'No upcoming events of interest to send.']);
}

$to = $user['email'];
$subject = 'Your Upcoming Local Greetings Events';
$message = "Hello {$user['username']},\n\nHere are the upcoming events for locations you are subscribed to:\n\n";

foreach ($events as $event) {
    $message .= "- {$event['title']} at {$event['location']['name']} ({$event['location']['area']}) on {$event['eventDate']}\n";
    $message .= "  Link: http://localhost/event-details.html?id={$event['id']}\n\n";
}

$message .= "Best regards,\nLocal Greetings Team";

$mailer = new Mailer();

if ($mailer->send($to, $subject, $message)) {
    Response::json(['message' => 'Email sent successfully!']);
} else {
    Response::json(['message' => 'Failed to send email. Please try again later.'], 500);
}
