<?php
// MailerFactory: picks the mail driver from the MAIL_DRIVER environment
// variable. This is the only place that knows which implementation exists;
// switching .env's MAIL_DRIVER is the only change needed to move from msmtp
// to a future SmtpMailer (spec section 6).

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;

class MailerFactory
{
    /**
     * Build the configured Mailer. Phase 1 ships only the msmtp driver; the
     * SmtpMailer (MAIL_DRIVER=smtp) is a later, optional phase. An unknown
     * value is logged and falls back to msmtp so a typo in .env never
     * silently disables mail delivery.
     */
    public static function fromEnv(): Mailer
    {
        $driver = Env::get('MAIL_DRIVER', 'msmtp');
        if ($driver !== 'msmtp') {
            app_log('MailerFactory: MAIL_DRIVER "' . $driver . '" is not available yet, using msmtp');
        }
        return new MsmtpMailer();
    }
}
