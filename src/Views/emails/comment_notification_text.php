<?php
// Plain-text email body for the new-comment notification, the alternative
// part of the multipart/alternative message. Same content as the HTML body.
/** @var string $authorName */ /** @var string $commenterName */
/** @var string $link */ /** @var string $body */
?>
Hi <?= e($authorName) ?>,

<?= e($commenterName) ?> just left this comment on one of your Camagru pictures:

<?= e($body) ?>

Open the link below in your browser to view the picture:

<?= e($link) ?>

You can turn these emails off on your account page.
