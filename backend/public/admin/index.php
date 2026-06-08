<?php

require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Router;
use App\Controllers\AdminController;
use App\Models\User;
use App\Models\Event;
use App\Models\Location;
use App\Support\Database;

$pdo = Database::connection();
$adminController = new AdminController(new User($pdo), new Event($pdo), new Location($pdo));

$router = new Router();

$router->add('GET', 'users', [$adminController, 'listUsers']);

$router->add('POST', 'user-ban', [$adminController, 'toggleUserBan']);

$router->add('GET', 'events', [$adminController, 'listEvents']);

$router->add('POST', 'event-ban', [$adminController, 'toggleEventBan']);

$router->add('GET', 'locations', [$adminController, 'listLocations']);

$router->add('GET', 'sports', [$adminController, 'listSports']);

$router->add('POST', 'location-create', [$adminController, 'createLocation']);

$router->add('POST', 'location-delete', [$adminController, 'deleteLocation']);

$router->dispatch();
