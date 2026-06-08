<?php

namespace App\Models;

use PDO;

final class User
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, password_hash, role, is_banned, email_verified_at FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function create(string $username, string $email, string $passwordHash): array
    {
        // Load admin configuration
        $adminConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php';
        $adminEmail = 'log70302@gmail.com'; // fallback
        if (file_exists($adminConfigPath)) {
            $config = require $adminConfigPath;
            if (is_array($config) && isset($config['admin_email'])) {
                $adminEmail = $config['admin_email'];
            }
        }

        $role = (strtolower($email) === strtolower($adminEmail)) ? 'admin' : 'user';

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, is_banned, created_at)
             VALUES (:username, :email, :password_hash, :role, 0, NOW())'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'is_banned' => 0,
            'email_verified_at' => null,
        ];
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
        ]);
    }

    public function createAuthToken(int $userId, string $tokenHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 7 DAY))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function findByAuthTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.role, users.is_banned, users.email_verified_at
             FROM auth_tokens
             INNER JOIN users ON users.id = auth_tokens.user_id
             WHERE auth_tokens.token_hash = :token_hash
               AND auth_tokens.expires_at > NOW()
               AND auth_tokens.revoked_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function revokeAuthToken(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    public function findLoginAttempt(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT email, attempt_count, locked_until
             FROM login_attempts
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $attempt = $statement->fetch();

        return $attempt ?: null;
    }

    public function saveLoginAttempt(string $email, int $attemptCount, ?string $lockedUntil): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, attempt_count, locked_until)
             VALUES (:email, :attempt_count, :locked_until)
             ON DUPLICATE KEY UPDATE
                 attempt_count = VALUES(attempt_count),
                 locked_until = VALUES(locked_until),
                 updated_at = NOW()'
        );
        $statement->execute([
            'email' => $email,
            'attempt_count' => $attemptCount,
            'locked_until' => $lockedUntil,
        ]);
    }

    public function deleteLoginAttempt(string $email): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
        $statement->execute(['email' => $email]);
    }

    public function createEmailVerification(int $userId, string $tokenHash): void
    {
        $this->deleteEmailVerifications($userId);

        $statement = $this->pdo->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function verifyEmailByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.role, users.is_banned, users.email_verified_at
             FROM email_verifications
             INNER JOIN users ON users.id = email_verifications.user_id
             WHERE email_verifications.token_hash = :token_hash
               AND email_verifications.expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        if (!$user) {
            return null;
        }

        $this->pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);
        $this->deleteEmailVerifications((int) $user['id']);
        $user['email_verified_at'] = date('Y-m-d H:i:s');

        return $user;
    }

    public function createPasswordReset(int $userId, string $tokenHash): void
    {
        $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
            ->execute(['user_id' => $userId]);

        $statement = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function findUserByPasswordResetTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.password_hash, users.role, users.is_banned, users.email_verified_at
             FROM password_resets
             INNER JOIN users ON users.id = password_resets.user_id
             WHERE password_resets.token_hash = :token_hash
               AND password_resets.expires_at > NOW()
               AND password_resets.used_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function markPasswordResetUsed(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    private function deleteEmailVerifications(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, role, is_banned, created_at FROM users ORDER BY created_at DESC'
        );
        $statement->execute();
        return $statement->fetchAll();
    }

    public function toggleBan(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT is_banned FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $isBanned = (bool) $statement->fetchColumn();

        $newStatus = !$isBanned;

        $statement = $this->pdo->prepare('UPDATE users SET is_banned = :is_banned WHERE id = :id');
        $statement->execute([
            'is_banned' => $newStatus ? 1 : 0,
            'id' => $id
        ]);

        if ($newStatus === true) {
            $statement = $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = :user_id');
            $statement->execute(['user_id' => $id]);
        }

        return $newStatus;
    }
}