<?php
// AppMailer: the domain-level mail API used by controllers. Every outgoing
// email of the application goes through one of its methods, which render the
// matching template in src/Views/emails/ and delegate to the Mailer
// implementation chosen by MailerFactory. Controllers never touch mail()
// or SMTP details.

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;
use App\Core\View;

class AppMailer
{
    private Mailer $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer ?? MailerFactory::fromEnv();
    }

    /**
     * Send the account-confirmation email with the unique verification link.
     * Returns true on success, false on failure (caller logs and continues;
     * a mail failure must never break the request).
     *
     * @param string      $to       Recipient email address.
     * @param string      $username Display name of the new account (escaped in the template).
     * @param string      $token    Raw verification token (the DB stores its hash).
     * @param string|null $baseUrl  Base URL for the link (see SiteUrl); defaults to APP_URL.
     */
    public function sendVerification(string $to, string $username, string $token, ?string $baseUrl = null): bool
    {
        // Public link built from the resolved base URL; the token is hex so
        // urlencode is a no-op, kept for safety.
        $base = rtrim($baseUrl ?? Env::require('APP_URL'), '/');
        $link = $base . '/verify?token=' . urlencode($token);

        $html = View::renderPartial('emails/verification.php', [
            'username' => $username,
            'link'     => $link,
        ]);
        $text = View::renderPartial('emails/verification_text.php', [
            'username' => $username,
            'link'     => $link,
        ]);

        return $this->mailer->send($to, 'Confirm your Camagru account', $html, $text);
    }

    /**
     * Send the password-reset email with the single-use link (valid one
     * hour). Returns true on success, false on failure (caller logs and
     * continues; a mail failure must never break the request).
     *
     * @param string      $to       Recipient email address.
     * @param string      $username Display name (escaped in the template).
     * @param string      $token    Raw reset token (the DB stores its hash).
     * @param string|null $baseUrl  Base URL for the link (see SiteUrl); defaults to APP_URL.
     */
    public function sendPasswordReset(string $to, string $username, string $token, ?string $baseUrl = null): bool
    {
        $base = rtrim($baseUrl ?? Env::require('APP_URL'), '/');
        $link = $base . '/reset-password?token=' . urlencode($token);

        $html = View::renderPartial('emails/reset_password.php', [
            'username' => $username,
            'link'     => $link,
        ]);
        $text = View::renderPartial('emails/reset_password_text.php', [
            'username' => $username,
            'link'     => $link,
        ]);

        return $this->mailer->send($to, 'Reset your Camagru password', $html, $text);
    }
}
