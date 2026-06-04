<?php

namespace App\Controllers;

use App\Models\User;
use App\Support\Csrf;
use App\Support\Mailer;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
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
            Response::json(['message' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
        }

        $users = $this->requireUsers();

        if ($users->findByEmail($email) !== null) {
            Response::json(['message' => 'Please correct the highlighted fields.', 'errors' => ['email' => 'This email is already registered.']], 409);
        }

        try {
            $user = $users->create($username, $email, password_hash($password, PASSWORD_DEFAULT));
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                Response::json(['message' => 'Please correct the highlighted fields.', 'errors' => ['email' => 'This email is already registered.']], 409);
            }

            Response::json(['message' => 'Account could not be created right now.'], 500);
        }

        $this->sendVerificationEmail($user);
        Session::login($user);
        Response::json(['message' => 'Account created. Check your email to verify the account.', 'user' => $this->publicUser($user)], 201);
    }

    public function login(array $payload): void
    {
        $this->verifyCsrf();

        $email = $this->cleanEmail($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            Response::json(['message' => 'Invalid email or password.'], 401);
        }

        if ($this->isLocked($email)) {
            Response::json(['message' => 'Too many login attempts. Try again in a few minutes.'], 429);
        }

        $users = $this->requireUsers();
        $user = $users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($email);
            Response::json(['message' => 'Invalid email or password.'], 401);
        }

        if ((bool) $user['is_banned']) {
            Response::json(['message' => 'This account is disabled.'], 403);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->clearFailedLogins($email);
        Session::login($user);
        Response::json(['message' => 'Logged in.', 'user' => $this->publicUser($user)]);
    }

    public function me(): void
    {
        $user = Session::user();
        Response::json(['authenticated' => $user !== null, 'user' => $user]);
    }

    public function logout(): void
    {
        $this->verifyCsrf();
        Session::logout();
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

        Session::login($user);
        Response::json(['message' => 'Email verified.', 'user' => $this->publicUser($user)]);
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
        } elseif (!$this->emailDomainAcceptsMail($email)) {
            $errors['email'] = 'Use an email domain that can receive mail.';
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

    private function isLocked(string $email): bool
    {
        $attempt = $_SESSION['login_attempts'][$email] ?? null;

        return is_array($attempt)
            && isset($attempt['locked_until'])
            && time() < (int) $attempt['locked_until'];
    }

    private function recordFailedLogin(string $email): void
    {
        $attempt = $_SESSION['login_attempts'][$email] ?? ['count' => 0, 'locked_until' => 0];
        $attempt['count'] = (int) $attempt['count'] + 1;

        if ($attempt['count'] >= self::MAX_LOGIN_ATTEMPTS) {
            $attempt['locked_until'] = time() + self::LOCK_SECONDS;
            $attempt['count'] = 0;
        }

        $_SESSION['login_attempts'][$email] = $attempt;
    }

    private function clearFailedLogins(string $email): void
    {
        unset($_SESSION['login_attempts'][$email]);
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

    private function emailDomainAcceptsMail(string $email): bool
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if ($domain === '') {
            return false;
        }

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
}
