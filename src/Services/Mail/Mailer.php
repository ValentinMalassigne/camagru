<?php
// Mailer: the single email abstraction of the application (spec section 6).
// No code outside this namespace may call mail() or open SMTP sockets.
// Implementations: MsmtpMailer (phase 1), SmtpMailer (optional, later phase).

declare(strict_types=1);

namespace App\Services\Mail;

interface Mailer
{
    /**
     * Send an email. Returns true on success, false on failure (the caller
     * logs the failure and continues; a mail problem must never break a
     * request).
     *
     * @param string      $to       Recipient email address.
     * @param string      $subject  Subject line (UTF-8, encoded by the builder).
     * @param string      $htmlBody HTML body (single part or multipart/alternative).
     * @param string|null $textBody Optional plain-text alternative body.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool;
}
