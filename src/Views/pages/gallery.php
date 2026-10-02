<?php
// Public gallery (spec section 4.3 + infinite-scroll bonus): every picture,
// newest first, 6 per page, with the author and the like and comment counts.
// The cards are rendered by partials/gallery_items.php, shared with the
// infinite-scroll JSON response. With JavaScript, gallery.js loads further
// pages as you scroll and hides the pagination nav; without it, the
// Previous/Next links below remain the fallback. Markup only; every value is
// escaped with e().
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
        <ul id="gallery-grid" class="gallery__grid" data-page="<?= (int) $page ?>"
            data-total-pages="<?= (int) $totalPages ?>">
            <?= \App\Core\View::renderPartial('partials/gallery_items.php', ['images' => $images]) ?>
        </ul>

        <?php if ($totalPages > 1): ?>
            <nav id="gallery-pagination" class="gallery-pagination">
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

    <!-- Versioned include: /assets/ is cached for 1 hour, so every change to
         gallery.js MUST bump this version (same rule as editor.js). -->
    <script src="/assets/js/gallery.js?v=1"></script>
</section>
