<?php

namespace App\Controllers;

use App\Models\Event;
use App\Models\Location;
use App\Models\User;
use App\Support\Auth;
use App\Support\Mailer;
use App\Support\Request;
use App\Support\Response;

final class EventController
{
    public function __construct(
        private Event $events,
        private User $users,
        private ?Location $locations = null,
        private ?Mailer $mailer = null
    )
    {
    }

    public function index(?int $locationId = null): void
    {
        $user = Auth::user($this->users);

        Response::json([
            'events' => $this->events->all($user !== null ? (int) $user['id'] : null, $locationId),
        ]);
    }

    public function show(int $id): void
    {
        $user = Auth::user($this->users);
        $event = $this->events->find($id, $user !== null ? (int) $user['id'] : null);

        if ($event === null) {
            Response::json(['message' => 'Event not found.'], 404);
        }

        Response::json(['event' => $event]);
    }

public function create(array $payload): void
{
    $user = Auth::user($this->users);

    if ($user === null) {
        Response::json(['message' => 'Authentication required.'], 401);
    }

    $errors = $this->events->validate($payload);

    if ($errors !== []) {
        Response::json(['message' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
    }

    $event = $this->events->create($this->events->cleanPayload($payload), (int) $user['id']);
    
    $this->events->join((int)$event['id'], (int)$user['id']);

    $subscriberCount = $this->notifyLocationSubscribers($event, (int) $user['id']);

    Response::json([
        'message' => 'Event created and you are registered.',
        'event' => $event,
    ], 201);
}

    public function join(int $id): void
    {
        $user = Auth::user($this->users);

        if ($user === null) {
            Response::json(['message' => 'Authentication required.'], 401);
        }

        $result = $this->events->join($id, (int) $user['id']);

        if (!$result['ok']) {
            Response::json(['message' => $result['message']], $result['status']);
        }

        Response::json([
            'message' => $result['message'],
            'event' => $result['event'],
        ]);
    }

    public function delete(int $id): void
    {
        $user = Auth::user($this->users);

        if ($user === null) {
            Response::json(['message' => 'Authentication required.'], 401);
        }

        $event = $this->events->find($id);

        if ($event === null) {
            Response::json(['message' => 'Event not found.'], 404);
        }

        if ((int) $event['organizerId'] !== (int) $user['id']) {
            Response::json(['message' => 'You are not authorized to delete this event.'], 403);
        }

        $this->events->delete($id);

        Response::json(['message' => 'Event deleted successfully.']);
    }

    private function notifyLocationSubscribers(array $event, int $organizerId): int
    {
        if ($this->locations === null || $this->mailer === null) {
            return 0;
        }

        $locationId = (int) ($event['location']['id'] ?? 0);

        if ($locationId <= 0) {
            return 0;
        }

        $recipients = $this->locations->subscriberEmailsForLocation($locationId, $organizerId);

    foreach ($recipients as $recipient) {
        $body = sprintf(
            "Hello %s,\n\nA new %s event was planned at %s:\nData: %s\nTitlu: %s\n\nOpen Local Greetings to join it.",
            $recipient['username'] ?: 'there',
            $event['sport'] ?? 'sports', // Sportul specificat
            $event['location']['name'] ?? 'your subscribed location',
            $event['eventDate'] ?? 'Date pending', // Data de început
            $event['title'] ?? 'New event'
        );

        $this->mailer->send($recipient['email'], 'New Local Greetings event nearby', $body);
    }

    return count($recipients);
}
}
