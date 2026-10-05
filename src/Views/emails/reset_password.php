<?php
// HTML email body for the password-reset mail. User content escaped; the
// link is built by AppMailer and expires one hour after the request.
/** @var string $username */ /** @var string $link */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reset your Camagru password</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 24px; background: #f7f7f9;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0"
                       style="max-width: 480px; background: #ffffff; border: 1px solid #ddd; border-radius: 6px; padding: 24px; text-align: left;">
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 12px; font-size: 20px;">Password reset requested</h1>
                            <p style="margin: 0 0 16px;">
                                Hi <?= e($username) ?>, a password reset was just requested for your
                                Camagru account. The link below is valid for one hour and can only
                                be used once:
                            </p>
                            <p style="margin: 0 0 16px;">
                                <a href="<?= e($link) ?>"
                                   style="background: #2a6; color: #ffffff; padding: 10px 16px; border-radius: 4px; text-decoration: none; display: inline-block;">
                                    Reset my password
                                </a>
                            </p>
                            <p style="margin: 0 0 16px; word-break: break-all; font-size: 13px; color: #555;">
                                If the button does not work, copy this link into your browser:<br>
                                <?= e($link) ?>
                            </p>
                            <p style="margin: 0; font-size: 13px; color: #777;">
                                If you did not request this reset, you can safely ignore this email:
                                your password stays unchanged as long as the link is not used.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
