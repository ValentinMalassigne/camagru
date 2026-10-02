<?php
// Public gallery (spec section 4.3): every picture, newest first, 6 per
// page, with the author and the like and comment counts. Visible to
// everyone; the counts are plain text here, the actions live on the detail
// page. Markup only; every value is escaped with e().
/** @var string $title */
/** @var array<int, array<string, mixed>> $images The current page's pictures. */
/** @var int $page Current page number (1-based). */
/** @var int $totalPages Total number of pages. */
?>
<section class="page page--gallery">
    <h1 class="page__title">Gallery</h1>

    <?php if (empty($images)): ?>
        <p class="gallery__empty">
            No pictures yet. Register, log in and head to the editor to post the first one!
        </p>
    <?php else: ?>
        <ul class="gallery__grid">
            <?php foreach ($images as $image): ?>
                <li class="gallery__item">
                    <a class="gallery__link" href="/images/<?= (int) $image['id'] ?>">
                        <img class="gallery__img" src="/uploads/<?= e_attr($image['filename']) ?>"
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
            <?php endforeach; ?>
        </ul>

        <?php if ($totalPages > 1): ?>
            <nav class="gallery-pagination">
                <?php if ($page > 1): ?>
                    <a class="gallery-pagination__link" href="/?page=<?= $page - 1 ?>">Previous</a>
                <?php else: ?>
                    <span class="gallery-pagination__link gallery-pagination__link--off">Previous</span>
                <?php endif; ?>
                <span class="gallery-pagination__current">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="gallery-pagination__link" href="/?page=<?= $page + 1 ?>">Next</a>
                <?php else: ?>
                    <span class="gallery-pagination__link gallery-pagination__link--off">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
