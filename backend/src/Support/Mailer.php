<?php

namespace App\Support;

final class Mailer
{
    public function send(string $to, string $subject, string $body): void
    {
        $this->writeLocalCopy($to, $subject, $body);

        $headers = [
            'From: Local Greetings <no-reply@local-greetings.test>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        @mail($to, $subject, $body, implode("\r\n", $headers));
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
}
