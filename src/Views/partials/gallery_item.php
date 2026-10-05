<?php
// One gallery card: used by the gallery page and by the infinite-scroll JSON
// response (gallery.js). Server-rendered so every value is escaped here with
// e(), never in the client.
/** @var array<string, mixed> $image One picture row (author and counts included). */
?>
<li class="gallery__item">
    <a class="gallery__link" href="/images/<?= (int) $image['id'] ?>">
        <img class="gallery__img" src="/uploads/<?= e($image['filename']) ?>"
             alt="Picture by <?= e((string) $image['username']) ?>">
    </a>
    <p class="gallery__meta">
        <a class="gallery__author" href="/images/<?= (int) $image['id'] ?>"><?= e((string) $image['username']) ?></a>
        <span class="gallery__counts">
            <?= (int) $image['like_count'] ?> like<?= ((int) $image['like_count'] === 1) ? '' : 's' ?>
            /
            <?= (int) $image['comment_count'] ?> comment<?= ((int) $image['comment_count'] === 1) ? '' : 's' ?>
        </span>
    </p>
</li>
