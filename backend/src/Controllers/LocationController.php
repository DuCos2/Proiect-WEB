<?php

namespace App\Controllers;

use App\Models\Location;
use App\Support\Response;

final class LocationController
{
    public function __construct(private Location $locations)
    {
    }

    public function index(): void
    {
        Response::json([
            'locations' => $this->locations->all(),
        ]);
    }
}