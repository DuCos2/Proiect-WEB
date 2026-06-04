<?php

namespace App\Support;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public function send(string $to, string $subject, string $body): void
    {
        $this->writeLocalCopy($to, $subject, $body);

        $config = $this->smtpConfig();

        if (!$this->isSmtpConfigured($config)) {
            return;
        }

        try {
            $this->sendViaSmtp($to, $subject, $body, $config);
        } catch (MailException $exception) {
            $this->writeMailError($to, $subject, $exception->getMessage());
        }
    }

    private function sendViaSmtp(string $to, string $subject, string $body, array $config): void
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = (int) $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = $this->smtpEncryption($config['encryption']);
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
    }

    private function smtpConfig(): array
    {
        $config = [
            'host' => getenv('LOG_SMTP_HOST') ?: '',
            'port' => (int) (getenv('LOG_SMTP_PORT') ?: 587),
            'username' => getenv('LOG_SMTP_USER') ?: '',
            'password' => getenv('LOG_SMTP_PASS') ?: '',
            'encryption' => getenv('LOG_SMTP_ENCRYPTION') ?: 'tls',
            'from_email' => getenv('LOG_MAIL_FROM') ?: '',
            'from_name' => getenv('LOG_MAIL_FROM_NAME') ?: 'Local Greetings',
        ];

        $localConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail.local.php';

        if (file_exists($localConfigPath)) {
            $localConfig = require $localConfigPath;

            if (is_array($localConfig)) {
                $config = array_merge($config, $localConfig);
            }
        }

        if ($config['from_email'] === '') {
            $config['from_email'] = $config['username'];
        }

        return $config;
    }

    private function isSmtpConfigured(array $config): bool
    {
        return $config['host'] !== ''
            && $config['username'] !== ''
            && $config['password'] !== ''
            && $config['from_email'] !== '';
    }

    private function smtpEncryption(string $encryption): string
    {
        return strtolower($encryption) === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
    }

    private function writeLocalCopy(string $to, string $subject, string $body): void
    {
        $storagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0775, true);
        }

        $entry = sprintf(
            "[%s]\nTo: %s\nSubject: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body
        );

        file_put_contents($storagePath . DIRECTORY_SEPARATOR . 'mail.log', $entry, FILE_APPEND | LOCK_EX);
    }

    private function writeMailError(string $to, string $subject, string $error): void
    {
        $storagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0775, true);
        }

        $entry = sprintf(
            "[%s]\nSMTP error for %s / %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $error
        );

        file_put_contents($storagePath . DIRECTORY_SEPARATOR . 'mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
