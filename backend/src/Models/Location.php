<?php

namespace App\Models;

use PDO;

final class Location
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?int $userId = null, array $filters = []): array
    {
        $whereConditions = ['1=1'];
        $params = [
            'user_id' => $userId,
            'subscription_user_id' => $userId,
        ];

        if (!empty($filters['sport']) && $filters['sport'] !== 'Any sport') {
            $whereConditions[] = 'EXISTS (SELECT 1 FROM location_sports ls JOIN sports s ON s.id = ls.sport_id WHERE ls.location_id = locations.id AND s.name = :sport)';
            $params['sport'] = $filters['sport'];
        }

        if (!empty($filters['area']) && $filters['area'] !== 'All of Iasi') {
            $whereConditions[] = 'locations.area = :area';
            $params['area'] = $filters['area'];
        }

        if (!empty($filters['q'])) {
            $whereConditions[] = '(locations.name LIKE :q OR locations.address LIKE :q OR locations.area LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }


        $whereSql = implode(' AND ', $whereConditions);

        $statement = $this->pdo->prepare("
            SELECT 
                locations.id, 
                locations.name, 
                locations.area, 
                locations.latitude, 
                locations.longitude, 
                locations.address,
                GROUP_CONCAT(DISTINCT sports.name ORDER BY sports.name ASC SEPARATOR ', ') AS sports,
                COUNT(DISTINCT events.id) AS active_events_count,
                CASE
                    WHEN :user_id IS NULL THEN 0
                    ELSE EXISTS (
                        SELECT 1
                        FROM user_subscriptions
                        WHERE user_subscriptions.user_id = :subscription_user_id
                          AND user_subscriptions.location_id = locations.id
                        LIMIT 1
                    )
                END AS is_subscribed
            FROM locations
            LEFT JOIN location_sports ON location_sports.location_id = locations.id
            LEFT JOIN sports ON sports.id = location_sports.sport_id
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = 'open' OR events.status IS NULL) AND (events.end_date >= NOW() OR events.end_date IS NULL)
            WHERE {$whereSql}
            GROUP BY locations.id
            ORDER BY locations.name ASC
        ");
        
        $statement->execute($params);

        return array_map(fn (array $row): array => $this->present($row), $statement->fetchAll());
    }

    public function allSports(): array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM sports ORDER BY name ASC');
        $statement->execute();

        return array_map(fn (array $sport): array => [
            'id' => (int) $sport['id'],
            'name' => $sport['name'],
        ], $statement->fetchAll());
    }

    public function validateAdminPayload(array $payload): array
    {
        $errors = [];
        $name = $this->cleanText($payload['name'] ?? '');
        $area = $this->cleanText($payload['area'] ?? '');
        $address = $this->cleanText($payload['address'] ?? '');
        $submittedSportIds = $payload['sport_ids'] ?? [];
        $sportIds = $this->normalizeSportIds($submittedSportIds);
        $latitude = $this->normalizeCoordinate($payload['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($payload['longitude'] ?? null);

        if ($name === '') {
            $errors['name'] = 'Location name is required.';
        }

        if ($area === '') {
            $errors['area'] = 'Area is required.';
        }

        if ($address === '') {
            $errors['address'] = 'Address is required.';
        }

        if ($this->hasInvalidSportIds($submittedSportIds)) {
            $errors['sport_ids'] = 'Choose only sports that already exist.';
        } elseif ($sportIds === []) {
            $errors['sport_ids'] = 'Choose at least one sport.';
        } elseif (!$this->sportIdsExist($sportIds)) {
            $errors['sport_ids'] = 'Choose only sports that already exist.';
        }

        if ($latitude === false || ($latitude !== null && ($latitude < -90 || $latitude > 90))) {
            $errors['latitude'] = 'Latitude must be between -90 and 90.';
        }

        if ($longitude === false || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
            $errors['longitude'] = 'Longitude must be between -180 and 180.';
        }

        if ($name !== '' && $area !== '' && $this->existsByNameAndArea($name, $area)) {
            $errors['name'] = 'This location already exists in the selected area.';
        }

        return $errors;
    }

    public function createFromAdmin(array $payload): array
    {
        $cityId = $this->findOrCreateCity('Iasi', 'Iasi');
        $name = $this->cleanText($payload['name'] ?? '');
        $area = $this->cleanText($payload['area'] ?? '');
        $address = $this->cleanText($payload['address'] ?? '');
        $latitude = $this->normalizeCoordinate($payload['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($payload['longitude'] ?? null);
        $sportIds = $this->normalizeSportIds($payload['sport_ids'] ?? []);

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO locations (city_id, name, area, latitude, longitude, address)
                 VALUES (:city_id, :name, :area, :latitude, :longitude, :address)'
            );
            $statement->execute([
                'city_id' => $cityId,
                'name' => $name,
                'area' => $area,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $address,
            ]);

            $locationId = (int) $this->pdo->lastInsertId();
            $this->syncSportIds($locationId, $sportIds);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $this->findPresentedById($locationId) ?? [
            'id' => $locationId,
            'name' => $name,
            'area' => $area,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address,
            'sports' => implode(', ', $this->sportNamesByIds($sportIds)),
            'activeEventsCount' => 0,
            'subscribed' => false,
        ];
    }

    public function deleteFromAdmin(int $locationId): bool
    {
        if (!$this->exists($locationId)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare('UPDATE events SET location_id = NULL WHERE location_id = :location_id');
            $statement->execute(['location_id' => $locationId]);

            $statement = $this->pdo->prepare('DELETE FROM user_subscriptions WHERE location_id = :location_id');
            $statement->execute(['location_id' => $locationId]);

            $statement = $this->pdo->prepare('DELETE FROM location_sports WHERE location_id = :location_id');
            $statement->execute(['location_id' => $locationId]);

            $statement = $this->pdo->prepare('DELETE FROM locations WHERE id = :id');
            $statement->execute(['id' => $locationId]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return true;
    }

    public function exists(int $locationId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM locations WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $locationId]);

        return (bool) $statement->fetch();
    }

    public function subscribe(int $userId, int $locationId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM user_subscriptions
             WHERE user_id = :user_id
               AND location_id = :location_id
               AND city_id IS NULL
               AND sport_id IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'location_id' => $locationId,
        ]);

        if ($statement->fetch()) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO user_subscriptions (user_id, location_id)
             VALUES (:user_id, :location_id)'
        );
        $statement->execute([
            'user_id' => $userId,
            'location_id' => $locationId,
        ]);
    }

    public function unsubscribe(int $userId, int $locationId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_subscriptions
             WHERE user_id = :user_id
               AND location_id = :location_id
               AND city_id IS NULL
               AND sport_id IS NULL'
        );
        $statement->execute([
            'user_id' => $userId,
            'location_id' => $locationId,
        ]);
    }

    public function subscriberEmailsForLocation(int $locationId, int $excludeUserId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT users.email, users.username
             FROM user_subscriptions
             INNER JOIN users ON users.id = user_subscriptions.user_id
             WHERE user_subscriptions.location_id = :location_id
               AND user_subscriptions.user_id <> :exclude_user_id
               AND users.is_banned = 0
               AND users.email <> ""'
        );
        $statement->execute([
            'location_id' => $locationId,
            'exclude_user_id' => $excludeUserId,
        ]);

        return $statement->fetchAll();
    }

    public function advancedFilteredLocations(?int $userId = null, array $filters = []): array
    {
        $whereConditions = ['1=1'];
        $params = [
            'user_id' => $userId,
            'subscription_user_id' => $userId,
        ];

        $eventConditions = ['events.location_id = locations.id'];

        // Must have an ongoing event that can be registered for
        $eventConditions[] = "(events.end_date >= NOW() OR events.end_date IS NULL)";

        if (!empty($filters['sport']) && $filters['sport'] !== 'Any sport') {
            $eventConditions[] = 'EXISTS (SELECT 1 FROM sports WHERE sports.id = events.sport_id AND sports.name = :event_sport)';
            $params['event_sport'] = $filters['sport'];
        }

        if (!empty($filters['area']) && $filters['area'] !== 'All of Iasi') {
            $whereConditions[] = 'locations.area = :area';
            $params['area'] = $filters['area'];
        }

        if (!empty($filters['date'])) {
            $eventConditions[] = 'DATE(events.event_date) = :date';
            $params['date'] = $filters['date'];
        }

        if (!empty($filters['level']) && $filters['level'] !== 'Any level') {
            $eventConditions[] = '(events.skill_level LIKE :level OR events.skill_level = \'All levels\')';
            $params['level'] = '%' . $filters['level'] . '%';
        }

        if (!empty($filters['min_spots'])) {
            $eventConditions[] = '(events.max_participants IS NULL OR (events.max_participants - (SELECT COUNT(*) FROM event_participants WHERE event_id = events.id)) >= :min_spots)';
            $params['min_spots'] = (int) $filters['min_spots'];
        }

        $eventConditions[] = "(events.status = 'open' OR events.status IS NULL)";

        $whereConditions[] = 'EXISTS (SELECT 1 FROM events WHERE ' . implode(' AND ', $eventConditions) . ')';

        $whereSql = implode(' AND ', $whereConditions);

        $statement = $this->pdo->prepare("
            SELECT 
                locations.id, 
                locations.name, 
                locations.area, 
                locations.latitude, 
                locations.longitude, 
                locations.address,
                GROUP_CONCAT(DISTINCT sports.name ORDER BY sports.name ASC SEPARATOR ', ') AS sports,
                COUNT(DISTINCT events.id) AS active_events_count,
                CASE
                    WHEN :user_id IS NULL THEN 0
                    ELSE EXISTS (
                        SELECT 1
                        FROM user_subscriptions
                        WHERE user_subscriptions.user_id = :subscription_user_id
                          AND user_subscriptions.location_id = locations.id
                        LIMIT 1
                    )
                END AS is_subscribed
            FROM locations
            LEFT JOIN location_sports ON location_sports.location_id = locations.id
            LEFT JOIN sports ON sports.id = location_sports.sport_id
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = 'open' OR events.status IS NULL) AND (events.end_date >= NOW() OR events.end_date IS NULL)
            WHERE {$whereSql}
            GROUP BY locations.id
            ORDER BY locations.name ASC
        ");
        
        $statement->execute($params);

        return array_map(fn (array $row): array => $this->present($row), $statement->fetchAll());
    }

    private function findPresentedById(int $locationId): ?array
    {
        $statement = $this->pdo->prepare("
            SELECT 
                locations.id, 
                locations.name, 
                locations.area, 
                locations.latitude, 
                locations.longitude, 
                locations.address,
                GROUP_CONCAT(DISTINCT sports.name ORDER BY sports.name ASC SEPARATOR ', ') AS sports,
                COUNT(DISTINCT events.id) AS active_events_count,
                0 AS is_subscribed
            FROM locations
            LEFT JOIN location_sports ON location_sports.location_id = locations.id
            LEFT JOIN sports ON sports.id = location_sports.sport_id
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = 'open' OR events.status IS NULL) AND (events.end_date >= NOW() OR events.end_date IS NULL)
            WHERE locations.id = :id
            GROUP BY locations.id
            LIMIT 1
        ");
        $statement->execute(['id' => $locationId]);
        $row = $statement->fetch();

        return $row ? $this->present($row) : null;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'area' => $row['area'],
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'address' => $row['address'],
            'sports' => $row['sports'] ?? '',
            'activeEventsCount' => (int) $row['active_events_count'],
            'subscribed' => (bool) $row['is_subscribed'],
        ];
    }

    private function existsByNameAndArea(string $name, string $area): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM locations WHERE LOWER(name) = LOWER(:name) AND LOWER(area) = LOWER(:area) LIMIT 1'
        );
        $statement->execute([
            'name' => $name,
            'area' => $area,
        ]);

        return (bool) $statement->fetch();
    }

    private function syncSportIds(int $locationId, array $sportIds): void
    {
        $statement = $this->pdo->prepare('DELETE FROM location_sports WHERE location_id = :location_id');
        $statement->execute(['location_id' => $locationId]);

        $statement = $this->pdo->prepare(
            'INSERT INTO location_sports (location_id, sport_id)
             VALUES (:location_id, :sport_id)'
        );

        foreach ($sportIds as $sportId) {
            $statement->execute([
                'location_id' => $locationId,
                'sport_id' => $sportId,
            ]);
        }
    }

    private function sportIdsExist(array $sportIds): bool
    {
        if ($sportIds === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($sportIds), '?'));
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sports WHERE id IN ({$placeholders})");
        $statement->execute($sportIds);

        return (int) $statement->fetchColumn() === count($sportIds);
    }

    private function sportNamesByIds(array $sportIds): array
    {
        if ($sportIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sportIds), '?'));
        $statement = $this->pdo->prepare("SELECT name FROM sports WHERE id IN ({$placeholders}) ORDER BY name ASC");
        $statement->execute($sportIds);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function findOrCreateCity(string $name, string $county): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM cities WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $statement->execute(['name' => $name]);
        $city = $statement->fetch();

        if ($city) {
            return (int) $city['id'];
        }

        $statement = $this->pdo->prepare('INSERT INTO cities (name, county) VALUES (:name, :county)');
        $statement->execute([
            'name' => $name,
            'county' => $county,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function normalizeSportIds(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $sportIds = [];

        foreach ($items as $item) {
            $sportId = filter_var($item, FILTER_VALIDATE_INT);

            if ($sportId !== false && $sportId > 0) {
                $sportIds[$sportId] = $sportId;
            }
        }

        return array_values($sportIds);
    }

    private function hasInvalidSportIds(mixed $value): bool
    {
        $items = is_array($value) ? $value : [$value];

        foreach ($items as $item) {
            $sportId = filter_var($item, FILTER_VALIDATE_INT);

            if ($sportId === false || $sportId <= 0) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCoordinate(mixed $value): float|bool|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_FLOAT);
    }

    private function cleanText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
    }
}
