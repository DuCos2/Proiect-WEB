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
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = 'open' OR events.status IS NULL) AND (events.event_date >= NOW() OR events.event_date IS NULL)
            WHERE {$whereSql}
            GROUP BY locations.id
            ORDER BY locations.name ASC
        ");
        
        $statement->execute($params);

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
                'subscribed' => (bool) $row['is_subscribed'],
            ];
        }, $statement->fetchAll());
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

    public function advancedSearch(?int $userId = null, array $filters = []): array
    {
        $whereConditions = ['1=1'];
        $params = [
            'user_id' => $userId,
            'subscription_user_id' => $userId,
        ];

        $eventConditions = ['events.location_id = locations.id'];

        // Must have an ongoing event that can be registered for
        $eventConditions[] = "(events.event_date >= NOW() OR events.event_date IS NULL)";

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
            LEFT JOIN events ON events.location_id = locations.id AND (events.status = 'open' OR events.status IS NULL) AND (events.event_date >= NOW() OR events.event_date IS NULL)
            WHERE {$whereSql}
            GROUP BY locations.id
            ORDER BY locations.name ASC
        ");
        
        $statement->execute($params);

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
                'subscribed' => (bool) $row['is_subscribed'],
            ];
        }, $statement->fetchAll());
    }
}
