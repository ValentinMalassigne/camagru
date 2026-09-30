<?php
// HTML email body for the account-confirmation mail. Every user-controlled
// value is escaped; the link is built by AppMailer from APP_URL + token.
/** @var string $username */ /** @var string $link */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Confirm your Camagru account</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 24px; background: #f7f7f9;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0"
                       style="max-width: 480px; background: #ffffff; border: 1px solid #ddd; border-radius: 6px; padding: 24px; text-align: left;">
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 12px; font-size: 20px;">Welcome to Camagru, <?= e($username) ?>!</h1>
                            <p style="margin: 0 0 16px;">
                                You (or someone with this email address) just created a Camagru
                                account with this address. To confirm the account and be able to
                                log in, click the link below:
                            </p>
                            <p style="margin: 0 0 16px;">
                                <a href="<?= e_attr($link) ?>"
                                   style="background: #2a6; color: #ffffff; padding: 10px 16px; border-radius: 4px; text-decoration: none; display: inline-block;">
                                    Confirm my account
                                </a>
                            </p>
                            <p style="margin: 0 0 16px; word-break: break-all; font-size: 13px; color: #555;">
                                If the button does not work, copy this link into your browser:<br>
                                <?= e($link) ?>
                            </p>
                            <p style="margin: 0; font-size: 13px; color: #777;">
                                If you did not request this account, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
