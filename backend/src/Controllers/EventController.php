<?php

namespace App\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;

final class EventController
{
    public function __construct(private Event $events, private User $users)
    {
    }

    public function index(): void
    {
        $user = Auth::user($this->users);

        Response::json([
            'events' => $this->events->all($user !== null ? (int) $user['id'] : null),
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
        $this->verifyCsrf();

        $user = Auth::user($this->users);

        if ($user === null) {
            Response::json(['message' => 'Authentication required.'], 401);
        }

        $errors = $this->events->validate($payload);

        if ($errors !== []) {
            Response::json([
                'message' => 'Please correct the highlighted fields.',
                'errors' => $errors,
            ], 422);
        }

        $event = $this->events->create($this->events->cleanPayload($payload), (int) $user['id']);

        Response::json([
            'message' => 'Event created.',
            'event' => $event,
        ], 201);
    }

    public function join(int $id): void
    {
        $this->verifyCsrf();

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
        $this->verifyCsrf();

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
    private function verifyCsrf(): void
    {
        if (!Csrf::isValid(Request::csrfToken())) {
            Response::json(['message' => 'Your session expired. Refresh the page and try again.'], 419);
        }
    }
}
