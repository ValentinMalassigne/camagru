<?php
// Plain-text email body for the account-confirmation mail, the alternative
// part of the multipart/alternative message. Same content as the HTML body.
/** @var string $username */ /** @var string $link */
?>
Welcome to Camagru, <?= e($username) ?>!

You (or someone with this email address) just created a Camagru
account with this address. To confirm the account and be able to
log in, open the link below in your browser:

<?= e($link) ?>

If you did not request this account, you can safely ignore this email.
