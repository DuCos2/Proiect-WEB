<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Event;
use App\Models\Location;
use App\Support\Auth;
use App\Support\Response;
use App\Support\Request;

final class AdminController
{
    public function __construct(private User $users, private Event $events, private Location $locations)
    {
        $user = Auth::user($this->users);
        if ($user === null || $user['role'] !== 'admin') {
            Response::json(['message' => 'Access denied. Administrator privileges required.'], 403);
        }
    }

    public function listUsers(): void
    {
        $allUsers = $this->users->all();
        Response::json(['users' => $allUsers]);
    }

    public function toggleUserBan(): void
    {
        $payload = Request::jsonBody();
        $userId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($userId === false || $userId === null) {
            Response::json(['message' => 'User ID is required.'], 422);
        }

        $admin = Auth::user($this->users);
        if ($admin !== null && (int)$admin['id'] === $userId) {
            Response::json(['message' => 'You cannot ban yourself.'], 400);
        }

        $newStatus = $this->users->toggleBan($userId);
        $message = $newStatus ? 'User banned successfully.' : 'User unbanned successfully.';

        Response::json(['message' => $message, 'is_banned' => $newStatus]);
    }

    public function listEvents(): void
    {
        $allEvents = $this->events->allAdmin();
        Response::json(['events' => $allEvents]);
    }

    public function toggleEventBan(): void
    {
        $payload = Request::jsonBody();
        $eventId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($eventId === false || $eventId === null) {
            Response::json(['message' => 'Event ID is required.'], 422);
        }

        $newStatus = $this->events->toggleBan($eventId);
        $message = ($newStatus === 'banned') ? 'Event banned successfully.' : 'Event unbanned successfully.';

        Response::json(['message' => $message, 'status' => $newStatus]);
    }

    public function listLocations(): void
    {
        Response::json(['locations' => $this->locations->all()]);
    }

    public function listSports(): void
    {
        Response::json(['sports' => $this->locations->allSports()]);
    }

    public function createLocation(): void
    {
        $payload = Request::jsonBody();
        $errors = $this->locations->validateAdminPayload($payload);

        if ($errors !== []) {
            Response::json([
                'message' => 'Please check the location details.',
                'errors' => $errors,
            ], 422);
        }

        $location = $this->locations->createFromAdmin($payload);

        Response::json([
            'message' => 'Location added successfully.',
            'location' => $location,
        ], 201);
    }

    public function deleteLocation(): void
    {
        $payload = Request::jsonBody();
        $locationId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($locationId === false || $locationId === null) {
            Response::json(['message' => 'Location ID is required.'], 422);
        }

        if (!$this->locations->deleteFromAdmin($locationId)) {
            Response::json(['message' => 'Location not found.'], 404);
        }

        Response::json(['message' => 'Location removed successfully.']);
    }
}
