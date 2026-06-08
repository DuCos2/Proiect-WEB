<?php

namespace App\Models;

use PDO;
use PDOException;

final class Event
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?int $userId = null, ?int $locationId = null): array
    {
        $where = [
            '(events.status IS NULL OR events.status = "open")',
            '(events.end_date >= NOW() OR events.end_date IS NULL)'
        ];
        $params = [];

        if ($locationId !== null) {
            $where[] = 'events.location_id = :location_id';
            $params['location_id'] = $locationId;
        }

        $statement = $this->pdo->prepare($this->baseSelect() . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY events.event_date ASC, events.created_at DESC
            LIMIT 30
        ');
        $statement->execute($params);

        return array_map(
            fn (array $event): array => $this->present($event, $userId),
            $statement->fetchAll()
        );
    }

    public function find(int $id, ?int $userId = null): ?array
    {
        $statement = $this->pdo->prepare($this->baseSelect() . '
            WHERE events.id = :id
            LIMIT 1
        ');
        $statement->execute(['id' => $id]);
        $event = $statement->fetch();

        return $event ? $this->present($event, $userId) : null;
    }

    public function create(array $payload, int $organizerId): array
    {
        $sportId = $this->findOrCreateSport($payload['sport']);
        $locationId = $this->findOrCreateLocation($payload);

        $statement = $this->pdo->prepare(
            'INSERT INTO events (
                location_id,
                sport_id,
                organizer_id,
                title,
                description,
                event_date,
                end_date,
                max_participants,
                skill_level,
                status,
                created_at
            ) VALUES (
                :location_id,
                :sport_id,
                :organizer_id,
                :title,
                :description,
                :event_date,
                :end_date,
                :max_participants,
                :skill_level,
                "open",
                NOW()
            )'
        );
        $statement->execute([
            'location_id' => $locationId,
            'sport_id' => $sportId,
            'organizer_id' => $organizerId,
            'title' => $payload['title'],
            'description' => $payload['description'],
            'event_date' => $payload['event_date'],
            'end_date' => $payload['end_date'],
            'max_participants' => $payload['max_participants'],
            'skill_level' => $payload['skill_level'],
        ]);

        return $this->find((int) $this->pdo->lastInsertId(), $organizerId) ?? [];
    }

    public function join(int $eventId, int $userId): array
    {
        $event = $this->find($eventId, $userId);

        if ($event === null) {
            return ['ok' => false, 'status' => 404, 'message' => 'Event not found.'];
        }

        if ($event['isFull'] && !$event['userJoined']) {
            return ['ok' => false, 'status' => 409, 'message' => 'This event is already full.'];
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO event_participants (event_id, user_id, joined_at)
                 VALUES (:event_id, :user_id, NOW())'
            );
            $statement->execute([
                'event_id' => $eventId,
                'user_id' => $userId,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        return [
            'ok' => true,
            'event' => $this->find($eventId, $userId),
            'message' => 'You are registered for this event.'
        ];
    }

    public function validate(array $payload): array
    {
        $errors = [];

        foreach (['title', 'sport', 'location', 'area', 'event_date', 'end_date'] as $field) {
            if ($this->cleanText($payload[$field] ?? '') === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        if ($this->cleanText($payload['description'] ?? '') === '') {
            $errors['description'] = 'This field is required.';
        }

        $maxParticipants = filter_var($payload['max_participants'] ?? null, FILTER_VALIDATE_INT);

        if ($maxParticipants === false || $maxParticipants < 2 || $maxParticipants > 100) {
            $errors['max_participants'] = 'Choose between 2 and 100 participants.';
        }

        $normalizedDate = $this->normalizeDate($payload['event_date'] ?? '');
        $normalizedEndDate = $this->normalizeDate($payload['end_date'] ?? '');
        
        if ($normalizedDate === null) {
            $errors['event_date'] = 'Choose a valid start date and time.';
        } elseif (strtotime($normalizedDate) < time()) {
            $errors['event_date'] = 'The event date cannot be in the past.';
        }

        if ($normalizedEndDate === null) {
            $errors['end_date'] = 'Choose a valid end date and time.';
        } elseif ($normalizedDate !== null && strtotime($normalizedEndDate) <= strtotime($normalizedDate)) {
            $errors['end_date'] = 'The end date must be after the start date.';
        }

        return $errors;
    }

    public function cleanPayload(array $payload): array
    {
        return [
            'title' => $this->cleanText($payload['title'] ?? ''),
            'sport' => $this->cleanText($payload['sport'] ?? ''),
            'location' => $this->cleanText($payload['location'] ?? ''),
            'area' => $this->cleanText($payload['area'] ?? ''),
            'description' => $this->cleanText($payload['description'] ?? ''),
            'event_date' => $this->normalizeDate($payload['event_date'] ?? ''),
            'end_date' => $this->normalizeDate($payload['end_date'] ?? ''),
            'max_participants' => (int) ($payload['max_participants'] ?? 0),
            'skill_level' => $this->cleanText($payload['skill_level'] ?? 'Mixed level') ?: 'Mixed level',
        ];
    }

    private function baseSelect(): string
    {
        return 'SELECT
                events.id,
                events.title,
                events.description,
                events.event_date,
                events.end_date,
                events.max_participants,
                events.skill_level,
                events.status,
                events.created_at,
                events.organizer_id,
                events.location_id,
                sports.name AS sport_name,
                locations.name AS location_name,
                locations.area AS location_area,
                locations.address AS location_address,
                cities.name AS city_name,
                users.username AS organizer_name,
                COALESCE(participant_stats.participant_count, 0) AS participant_count,
                participant_stats.participant_ids
            FROM events
            LEFT JOIN sports ON sports.id = events.sport_id
            LEFT JOIN locations ON locations.id = events.location_id
            LEFT JOIN cities ON cities.id = locations.city_id
            LEFT JOIN users ON users.id = events.organizer_id
            LEFT JOIN (
                SELECT event_id, COUNT(*) AS participant_count, GROUP_CONCAT(user_id) AS participant_ids
                FROM event_participants
                GROUP BY event_id
            ) participant_stats ON participant_stats.event_id = events.id';
    }

    private function present(array $event, ?int $userId): array
    {
        $maxParticipants = $event['max_participants'] !== null ? (int) $event['max_participants'] : null;
        $participantCount = (int) ($event['participant_count'] ?? 0);
        $participantIds = array_filter(explode(',', (string) ($event['participant_ids'] ?? '')));
        $userJoined = $userId !== null && in_array((string) $userId, $participantIds, true);
        $spotsLeft = $maxParticipants !== null ? max(0, $maxParticipants - $participantCount) : null;

        return [
            'id' => (int) $event['id'],
            'title' => $event['title'] ?? 'Untitled event',
            'description' => $event['description'] ?? '',
            'eventDate' => $event['event_date'],
            'endDate' => $event['end_date'],
            'maxParticipants' => $maxParticipants,
            'participantCount' => $participantCount,
            'spotsLeft' => $spotsLeft,
            'isFull' => $spotsLeft !== null && $spotsLeft <= 0,
            'userJoined' => $userJoined,
            'skillLevel' => $event['skill_level'] ?? 'Mixed level',
            'status' => $event['status'] ?? 'open',
            'createdAt' => $event['created_at'],
            'organizerId' => isset($event['organizer_id']) ? (int) $event['organizer_id'] : null,
            'participants' => $this->participants((int) $event['id']),
            'sport' => $event['sport_name'] ?? 'Sport',
            'location' => [
                'id' => isset($event['location_id']) ? (int) $event['location_id'] : null,
                'name' => $event['location_name'] ?? 'Location pending',
                'area' => $event['location_area'] ?? 'Iasi',
                'address' => $event['location_address'] ?? '',
                'city' => $event['city_name'] ?? 'Iasi',
            ],
            'organizer' => $event['organizer_name'] ?? 'Local Greetings member',
        ];
    }

    private function findOrCreateSport(string $name): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM sports WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $statement->execute(['name' => $name]);
        $sport = $statement->fetch();

        if ($sport) {
            return (int) $sport['id'];
        }

        $statement = $this->pdo->prepare('INSERT INTO sports (name) VALUES (:name)');
        $statement->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function findOrCreateLocation(array $payload): int
    {
        $cityId = $this->findOrCreateCity('Iasi', 'Iasi');
        $statement = $this->pdo->prepare(
            'SELECT id FROM locations
             WHERE city_id = :city_id AND LOWER(name) = LOWER(:name) AND LOWER(area) = LOWER(:area)
             LIMIT 1'
        );
        $statement->execute([
            'city_id' => $cityId,
            'name' => $payload['location'],
            'area' => $payload['area'],
        ]);
        $location = $statement->fetch();

        if ($location) {
            return (int) $location['id'];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO locations (city_id, name, area, address)
             VALUES (:city_id, :name, :area, :address)'
        );
        $statement->execute([
            'city_id' => $cityId,
            'name' => $payload['location'],
            'area' => $payload['area'],
            'address' => $payload['location'],
        ]);

        return (int) $this->pdo->lastInsertId();
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

    private function cleanText(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    public function participants(int $eventId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.username
             FROM event_participants
             INNER JOIN users ON users.id = event_participants.user_id
             WHERE event_participants.event_id = :event_id
             ORDER BY event_participants.joined_at ASC'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM event_participants WHERE event_id = :event_id');
        $statement->execute(['event_id' => $id]);

        $statement = $this->pdo->prepare('DELETE FROM event_comments WHERE event_id = :event_id');
        $statement->execute(['event_id' => $id]);

        $statement = $this->pdo->prepare('DELETE FROM events WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
