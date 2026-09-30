<?php
// Plain-text email body for the password-reset mail (alternative part).
/** @var string $username */ /** @var string $link */
?>
Hi <?= e($username) ?>, a password reset was just requested for your
Camagru account. The link below is valid for one hour and can only be
used once:

<?= e($link) ?>

If you did not request this reset, you can safely ignore this email:
your password stays unchanged as long as the link is not used.
