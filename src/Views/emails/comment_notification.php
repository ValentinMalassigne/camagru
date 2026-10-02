<?php
// HTML email body for the new-comment notification. Every user-controlled
// value is escaped; the link is built by AppMailer from the resolved base
// URL + image id.
/** @var string $authorName */ /** @var string $commenterName */
/** @var string $link */ /** @var string $body */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New comment on your Camagru picture</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 24px; background: #f7f7f9;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0"
                       style="max-width: 480px; background: #ffffff; border: 1px solid #ddd; border-radius: 6px; padding: 24px; text-align: left;">
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 12px; font-size: 20px;">
                                <?= e($commenterName) ?> commented on your picture!
                            </h1>
                            <p style="margin: 0 0 16px;">
                                Hi <?= e($authorName) ?>, <?= e($commenterName) ?> just left this
                                comment on one of your Camagru pictures:
                            </p>
                            <p style="margin: 0 0 16px; padding: 12px; background: #f7f7f9; border-left: 3px solid #2a6;">
                                <?= nl2br(e($body)) ?>
                            </p>
                            <p style="margin: 0 0 16px;">
                                <a href="<?= e_attr($link) ?>"
                                   style="background: #2a6; color: #ffffff; padding: 10px 16px; border-radius: 4px; text-decoration: none; display: inline-block;">
                                    View the picture
                                </a>
                            </p>
                            <p style="margin: 0 0 16px; word-break: break-all; font-size: 13px; color: #555;">
                                If the button does not work, copy this link into your browser:<br>
                                <?= e($link) ?>
                            </p>
                            <p style="margin: 0; font-size: 13px; color: #777;">
                                You can turn these emails off on your account page.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
