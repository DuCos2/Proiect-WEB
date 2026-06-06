<?php

namespace App\Models;

use DateTimeImmutable;
use PDO;

final class ProfileStats
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forUser(int $userId): array
    {
        $events = $this->participatedEvents($userId);
        $createdEvents = $this->createdEventsCount($userId);
        $upcomingEvents = $this->upcomingEvents($userId);
        $eventIds = array_map(fn (array $event): int => (int) $event['id'], $events);
        $participantCounts = $this->participantCounts($eventIds);

        return [
            'gamesPlayed' => count($events),
            'sportsPlayed' => count(array_unique(array_filter(array_column($events, 'sport')))),
            'createdEvents' => $createdEvents,
            'favoriteHour' => $this->favoriteHour($events),
            'averageGroupSize' => $this->averageGroupSize($eventIds, $participantCounts),
            'currentStreak' => $this->currentStreak($events),
            'sportMix' => $this->percentages($events, 'sport'),
            'zones' => $this->percentages($events, 'zone'),
            'preferences' => [
                'favoriteSport' => $this->topLabel($events, 'sport'),
                'favoriteArea' => $this->topLabel($events, 'zone'),
                'level' => $this->topLabel($events, 'skill_level'),
            ],
            'upcomingEvents' => $upcomingEvents,
        ];
    }

    private function participatedEvents(int $userId): array
    {
        $zoneExpression = $this->zoneExpression();
        $statement = $this->pdo->prepare(
            "SELECT events.id,
                    events.title,
                    events.event_date,
                    events.skill_level,
                    COALESCE(sports.name, 'Unknown') AS sport,
                    {$zoneExpression} AS zone
             FROM event_participants
             INNER JOIN events ON events.id = event_participants.event_id
             LEFT JOIN sports ON sports.id = events.sport_id
             LEFT JOIN locations ON locations.id = events.location_id
             WHERE event_participants.user_id = :user_id
               AND (events.event_date IS NULL OR events.event_date <= NOW())
             ORDER BY events.event_date DESC"
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    private function createdEventsCount(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function upcomingEvents(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT events.title, events.event_date
             FROM events
             LEFT JOIN event_participants ON event_participants.event_id = events.id
             WHERE (event_participants.user_id = :participant_id OR events.organizer_id = :organizer_id)
               AND events.event_date >= NOW()
             ORDER BY events.event_date ASC
             LIMIT 3"
        );
        $statement->execute([
            'participant_id' => $userId,
            'organizer_id' => $userId,
        ]);

        return array_map(function (array $event): array {
            return [
                'title' => $event['title'] ?? 'Untitled event',
                'dateLabel' => $this->dateLabel($event['event_date'] ?? null),
            ];
        }, $statement->fetchAll());
    }

    private function participantCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT event_id, COUNT(*) AS total
             FROM event_participants
             WHERE event_id IN ({$placeholders})
             GROUP BY event_id"
        );
        $statement->execute($eventIds);
        $counts = [];

        foreach ($statement->fetchAll() as $row) {
            $counts[(int) $row['event_id']] = (int) $row['total'];
        }

        return $counts;
    }

    private function percentages(array $events, string $field): array
    {
        $counts = $this->countsByField($events, $field);
        $total = array_sum($counts);

        if ($total === 0) {
            return [];
        }

        arsort($counts);

        return array_map(fn (string $label, int $count): array => [
            'label' => $label,
            'percent' => (int) round(($count / $total) * 100),
        ], array_keys($counts), $counts);
    }

    private function topLabel(array $events, string $field): string
    {
        $counts = $this->countsByField($events, $field);

        if ($counts === []) {
            return 'No data yet';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function countsByField(array $events, string $field): array
    {
        $counts = [];

        foreach ($events as $event) {
            $label = trim((string) ($event[$field] ?? ''));

            if ($label === '') {
                $label = 'Unknown';
            }

            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $counts;
    }

    private function favoriteHour(array $events): string
    {
        $counts = [];

        foreach ($events as $event) {
            if (empty($event['event_date'])) {
                continue;
            }

            $hour = date('H:00', strtotime((string) $event['event_date']));
            $counts[$hour] = ($counts[$hour] ?? 0) + 1;
        }

        if ($counts === []) {
            return 'No data yet';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function averageGroupSize(array $eventIds, array $participantCounts): string
    {
        if ($eventIds === []) {
            return '0';
        }

        $total = 0;

        foreach ($eventIds as $eventId) {
            $total += $participantCounts[$eventId] ?? 1;
        }

        return (string) round($total / count($eventIds), 1);
    }

    private function currentStreak(array $events): string
    {
        $weeks = [];

        foreach ($events as $event) {
            if (empty($event['event_date'])) {
                continue;
            }

            $date = new DateTimeImmutable((string) $event['event_date']);
            $weeks[$date->format('o-W')] = true;
        }

        $cursor = new DateTimeImmutable('monday this week');
        $count = 0;

        while (isset($weeks[$cursor->format('o-W')])) {
            $count++;
            $cursor = $cursor->modify('-1 week');
        }

        return $count . ' ' . ($count === 1 ? 'week' : 'weeks');
    }

    private function dateLabel(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'Upcoming';
        }

        $eventDate = new DateTimeImmutable($date);
        $today = new DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');

        if ($eventDate->format('Y-m-d') === $today->format('Y-m-d')) {
            return 'Today';
        }

        if ($eventDate->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
            return 'Tomorrow';
        }

        return $eventDate->format('M j');
    }

    private function zoneExpression(): string
    {
        if ($this->hasColumn('locations', 'area')) {
            return "COALESCE(NULLIF(locations.area, ''), NULLIF(locations.address, ''), locations.name, 'Unknown')";
        }

        return "COALESCE(NULLIF(locations.address, ''), locations.name, 'Unknown')";
    }

    private function hasColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }
}
