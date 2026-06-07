<?php

namespace App\Controllers;

use App\Models\User;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Mailer;
use App\Support\Request;
use App\Support\Response;
use App\Support\Url;
use PDOException;

final class UserController
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCK_SECONDS = 600;

    public function __construct(private ?User $users = null, private ?Mailer $mailer = null)
    {
    }

    public function register(array $payload): void
    {
        $this->verifyCsrf();

        $username = $this->cleanName($payload['username'] ?? '');
        $email = $this->cleanEmail($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        $errors = $this->validateRegistration($username, $email, $password);

        if ($errors !== []) {
            Response::json([
                'message' => 'Please correct the highlighted fields.',
                'errors' => $errors
            ], 422);
        }

        $users = $this->requireUsers();

        if ($users->findByEmail($email) !== null) {
            Response::json([
                'message' => 'Please correct the highlighted fields.',
                'errors' => [
                    'email' => 'This email is already registered.'
                ]
            ], 409);
        }

        try {
            $user = $users->create(
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT)
            );
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::json([
                    'message' => 'Please correct the highlighted fields.',
                    'errors' => [
                        'email' => 'This email is already registered.'
                    ]
                ], 409);
            }

            Response::json([
                'message' => 'Account could not be created right now.'
            ], 500);
        }

        try {
            $this->sendVerificationEmail($user);
            $message = 'Account created. Check your email to verify the account.';
        } catch (\Throwable $exception) {
            $message = 'Account created.';
        }

        Response::json([
            'message' => $message,
        ], 201);
    }

    public function login(array $payload): void
    {
        $this->verifyCsrf();

        $email = $this->cleanEmail($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            Response::json(['message' => 'Invalid email or password.'], 401);
        }

        $users = $this->requireUsers();

        if ($this->isLocked($users, $email)) {
            Response::json(['message' => 'Too many login attempts. Try again in a few minutes.'], 429);
        }

        $user = $users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($users, $email);
            Response::json(['message' => 'Invalid email or password.'], 401);
        }

        if ((bool) $user['is_banned']) {
            Response::json(['message' => 'This account is disabled.'], 403);
        }

        if ($user['email_verified_at'] === null) {
            try {
                $this->sendVerificationEmail($user);
            } catch (\Throwable $exception) {
            }

            Response::json(['message' => 'Please verify your email before logging in. A new verification link was sent.'], 403);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $token = $this->newToken();
        $users->createAuthToken((int) $user['id'], Auth::tokenHash($token));

        $this->clearFailedLogins($users, $email);
        Response::json(['message' => 'Logged in.', 'user' => $this->publicUser($user), 'token' => $token]);
    }

    public function me(): void
    {
        $user = Auth::user($this->users);
        Response::json([
            'authenticated' => $user !== null,
            'user' => $user !== null ? $this->publicUser($user) : null,
        ]);
    }

    public function logout(): void
    {
        $this->verifyCsrf();
        $token = Request::bearerToken();

        if ($token !== null && $this->users instanceof User) {
            $this->users->revokeAuthToken(Auth::tokenHash($token));
        }

        Response::json(['message' => 'Logged out.']);
    }

    public function requestPasswordReset(array $payload): void
    {
        $this->verifyCsrf();

        $email = $this->cleanEmail($payload['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['message' => 'If that email exists, a reset link was sent.']);
        }

        $users = $this->requireUsers();
        $user = $users->findByEmail($email);

        if ($user !== null && !(bool) $user['is_banned']) {
            $token = $this->newToken();
            $users->createPasswordReset((int) $user['id'], $this->tokenHash($token));

            $link = Url::appUrl('reset-password.html?token=' . urlencode($token));
            $this->requireMailer()->send(
                $user['email'],
                'Reset your Local Greetings password',
                "Hello {$user['username']},\n\nUse this link to set a new password:\n{$link}\n\nThe link expires in 1 hour."
            );
        }

        Response::json(['message' => 'If that email exists, a reset link was sent.']);
    }

    public function resetPassword(array $payload): void
    {
        $this->verifyCsrf();

        $token = trim((string) ($payload['token'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $errors = [];

        if ($token === '') {
            $errors['token'] = 'Reset token is missing.';
        }

        if (strlen($password) < 8 || strlen($password) > 255) {
            $errors['password'] = 'Password must be between 8 and 255 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Password must contain at least one letter and one number.';
        }

        if ($errors !== []) {
            Response::json(['message' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
        }

        $users = $this->requireUsers();
        $tokenHash = $this->tokenHash($token);
        $user = $users->findUserByPasswordResetTokenHash($tokenHash);

        if ($user === null) {
            Response::json(['message' => 'This reset link is invalid or expired.'], 400);
        }

        $users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        $users->markPasswordResetUsed($tokenHash);
        Response::json(['message' => 'Password updated. You can log in now.']);
    }

    public function verifyEmail(array $payload): void
    {
        $this->verifyCsrf();

        $token = trim((string) ($payload['token'] ?? ''));

        if ($token === '') {
            Response::json(['message' => 'Verification token is missing.'], 422);
        }

        $user = $this->requireUsers()->verifyEmailByTokenHash($this->tokenHash($token));

        if ($user === null) {
            Response::json(['message' => 'This verification link is invalid or expired.'], 400);
        }

        $token = $this->newToken();
        $this->requireUsers()->createAuthToken((int) $user['id'], Auth::tokenHash($token));

        Response::json(['message' => 'Email verified.', 'user' => $this->publicUser($user), 'token' => $token]);
    }

    public function resendVerification(array $payload): void
    {
        $this->verifyCsrf();

        $email = $this->cleanEmail($payload['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['message' => 'If that account exists, a verification link was sent.']);
        }

        $user = $this->requireUsers()->findByEmail($email);

        if ($user !== null && $user['email_verified_at'] === null && !(bool) $user['is_banned']) {
            $this->sendVerificationEmail($user);
        }

        Response::json(['message' => 'If that account exists, a verification link was sent.']);
    }

    private function verifyCsrf(): void
    {
        if (!Csrf::isValid(Request::csrfToken())) {
            Response::json(['message' => 'Your session expired. Refresh the page and try again.'], 419);
        }
    }

    private function validateRegistration(string $username, string $email, string $password): array
    {
        $errors = [];

        if ($this->textLength($username) < 2 || $this->textLength($username) > 80) {
            $errors['username'] = 'Name must be between 2 and 80 characters.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $this->textLength($email) > 255) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if (strlen($password) < 8 || strlen($password) > 255) {
            $errors['password'] = 'Password must be between 8 and 255 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Password must contain at least one letter and one number.';
        }

        return $errors;
    }

    private function cleanName(mixed $name): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $name)) ?: '';
    }

    private function cleanEmail(mixed $email): string
    {
        $email = trim((string) $email);

        return function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'emailVerified' => $user['email_verified_at'] !== null,
        ];
    }

    private function isLocked(User $users, string $email): bool
    {
        $attempt = $users->findLoginAttempt($email);

        return is_array($attempt)
            && isset($attempt['locked_until'])
            && $attempt['locked_until'] !== null
            && strtotime((string) $attempt['locked_until']) > time();
    }

    private function recordFailedLogin(User $users, string $email): void
    {
        $attempt = $users->findLoginAttempt($email) ?? ['attempt_count' => 0, 'locked_until' => null];
        $attemptCount = (int) $attempt['attempt_count'] + 1;
        $lockedUntil = null;

        if ($attemptCount >= self::MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
            $attemptCount = 0;
        }

        $users->saveLoginAttempt($email, $attemptCount, $lockedUntil);
    }

    private function clearFailedLogins(User $users, string $email): void
    {
        $users->deleteLoginAttempt($email);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function requireUsers(): User
    {
        if (!$this->users instanceof User) {
            Response::json(['message' => 'Server error.'], 500);
        }

        return $this->users;
    }

    private function requireMailer(): Mailer
    {
        if (!$this->mailer instanceof Mailer) {
            $this->mailer = new Mailer();
        }

        return $this->mailer;
    }

    private function sendVerificationEmail(array $user): void
    {
        $token = $this->newToken();
        $this->requireUsers()->createEmailVerification((int) $user['id'], $this->tokenHash($token));

        $link = Url::appUrl('verify-email.html?token=' . urlencode($token));
        $this->requireMailer()->send(
            $user['email'],
            'Verify your Local Greetings email',
            "Hello {$user['username']},\n\nUse this link to verify your account:\n{$link}\n\nThe link expires in 24 hours."
        );
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

}
