<?php
// One comment entry: used by the image detail page loop and by the AJAX
// comment response (image.js). Server-rendered so the body and the author
// name are escaped here with e(), never in the client; newlines are preserved
// by white-space: pre-wrap in the stylesheet.
/** @var array<string, mixed> $comment One comment row (username and formatted date included). */
?>
<li class="comments__item">
    <p class="comments__meta">
        <span class="comments__author"><?= e((string) $comment['username']) ?></span>
        <span class="comments__date"><?= e((string) $comment['date']) ?></span>
    </p>
    <p class="comments__body"><?= e((string) $comment['body']) ?></p>
</li>
