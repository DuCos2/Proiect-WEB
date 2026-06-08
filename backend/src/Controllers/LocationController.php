<?php

namespace App\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Support\Auth;
use App\Support\Response;

final class LocationController
{
    public function __construct(private Location $locations, private ?User $users = null)
    {
    }

public function index(): void
    {
        $user = $this->users !== null ? Auth::user($this->users) : null;
        
        $filters = [
            'sport' => $_GET['sport'] ?? null,
            'area' => $_GET['area'] ?? null,
            'q' => $_GET['q'] ?? null,
        ];

        Response::json([
            'locations' => $this->locations->all($user !== null ? (int) $user['id'] : null, $filters),
        ]);
    }

    public function subscribe(array $payload): void
    {
        $user = $this->users !== null ? Auth::user($this->users) : null;

        if ($user === null) {
            Response::json(['message' => 'Authentication required.'], 401);
        }

        $locationId = filter_var($payload['location_id'] ?? null, FILTER_VALIDATE_INT);

        if ($locationId === false || $locationId === null || !$this->locations->exists($locationId)) {
            Response::json(['message' => 'Location id is required.'], 422);
        }

        $this->locations->subscribe((int) $user['id'], $locationId);

        Response::json(['message' => 'You are subscribed to this location.', 'subscribed' => true]);
    }

    public function unsubscribe(array $payload): void
    {
        $user = $this->users !== null ? Auth::user($this->users) : null;

        if ($user === null) {
            Response::json(['message' => 'Authentication required.'], 401);
        }

        $locationId = filter_var($payload['location_id'] ?? null, FILTER_VALIDATE_INT);

        if ($locationId === false || $locationId === null || !$this->locations->exists($locationId)) {
            Response::json(['message' => 'Location id is required.'], 422);
        }

        $this->locations->unsubscribe((int) $user['id'], $locationId);

        Response::json(['message' => 'You are no longer subscribed to this location.', 'subscribed' => false]);
    }
}
