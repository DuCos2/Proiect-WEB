<?php

namespace App\Models;

use PDO;

final class Location
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('
            SELECT 
                locations.id, 
                locations.name, 
                locations.area, 
                locations.latitude, 
                locations.longitude, 
                locations.address,
                GROUP_CONCAT(DISTINCT sports.name ORDER BY sports.name ASC SEPARATOR ", ") AS sports,
                COUNT(DISTINCT events.id) AS active_events_count
            FROM locations
            LEFT JOIN location_sports ON location_sports.location_id = locations.id
            LEFT JOIN sports ON sports.id = location_sports.sport_id
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = "open" OR events.status IS NULL) AND (events.event_date >= NOW() OR events.event_date IS NULL)
            GROUP BY locations.id
            ORDER BY locations.name ASC
        ');
        $statement->execute();

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'area' => $row['area'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'address' => $row['address'],
                'sports' => $row['sports'],
                'activeEventsCount' => (int) $row['active_events_count'],
            ];
        }, $statement->fetchAll());
    }
}